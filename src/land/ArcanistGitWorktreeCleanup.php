<?php

/**
 * A cleanup plan for the current linked checkout, never the primary checkout.
 */
final class ArcanistGitWorktreeCleanup extends Phobject {

  private $api;
  private $path;
  private $commonDirectory;
  private $branch;
  private $commit;
  private $keepBranch;

  public static function newPlan(ArcanistGitAPI $api, $keep_branch) {
    $plan = new self();
    $plan->api = $api;
    $plan->path = Filesystem::resolvePath($api->getPath());
    $plan->keepBranch = $keep_branch;

    list($git_dir) = $api->execxLocal('rev-parse --git-dir');
    list($common_dir) = $api->execxLocal('rev-parse --git-common-dir');
    $git_dir = Filesystem::resolvePath(trim($git_dir), $plan->path);
    $plan->commonDirectory = Filesystem::resolvePath(
      trim($common_dir),
      $plan->path);

    if ($git_dir === $plan->commonDirectory) {
      throw new PhutilArgumentUsageException(
        pht(
          'Worktree cleanup requires a linked Git worktree, not the '.
          'primary checkout.'));
    }

    $record = $plan->getRecord();
    if (!isset($record['branch'])) {
      throw new PhutilArgumentUsageException(
        pht(
          'Worktree cleanup requires a checked-out local branch, not '.
          'detached HEAD.'));
    }
    $plan->branch = substr($record['branch'], strlen('refs/heads/'));
    $plan->commit = $record['HEAD'];
    $plan->assertReady();
    return $plan;
  }

  public static function readWorktrees(ArcanistGitAPI $api) {
    list($data) = $api->execxLocal('worktree list --porcelain -z');
    $records = array();
    foreach (explode("\0\0", $data) as $entry) {
      $record = array();
      foreach (explode("\0", $entry) as $field) {
        if (!strlen($field)) {
          continue;
        }
        $parts = explode(' ', $field, 2);
        $record[$parts[0]] = idx($parts, 1, '');
      }
      if (isset($record['worktree'])) {
        $records[] = $record;
      }
    }
    return $records;
  }

  public function getBranch() {
    return $this->branch;
  }

  public function getPath() {
    return $this->path;
  }

  private function getRecord() {
    foreach (self::readWorktrees($this->api) as $record) {
      if (Filesystem::resolvePath($record['worktree']) !== $this->path) {
        continue;
      }
      if (isset($record['locked']) || isset($record['prunable'])) {
        throw new PhutilArgumentUsageException(
          pht(
            'The worktree is locked or prunable; refusing automatic '.
            'cleanup.'));
      }
      return $record;
    }
    throw new PhutilArgumentUsageException(
      pht('The current checkout is not a registered Git worktree.'));
  }

  private function assertClean() {
    list($status) = $this->api->execxLocal(
      'status --porcelain -z --untracked-files=all --ignore-submodules=none');
    if (strlen($status)) {
      throw new PhutilArgumentUsageException(
        pht(
          'Worktree cleanup requires a clean checkout, including '.
          'untracked files. Commit or move local changes first.'));
    }

    list($index) = $this->api->execxLocal('ls-files --stage -z');
    foreach (explode("\0", $index) as $entry) {
      if (strncmp($entry, '160000 ', 7) === 0) {
        throw new PhutilArgumentUsageException(
          pht(
            'Automatic cleanup of worktrees containing submodules is '.
            'not supported.'));
      }
    }
  }

  public function assertReady() {
    $record = $this->getRecord();
    if (idx($record, 'branch') !== 'refs/heads/'.$this->branch ||
        idx($record, 'HEAD') !== $this->commit) {
      throw new PhutilArgumentUsageException(
        pht('The worktree branch changed after cleanup was requested.'));
    }
    $this->assertClean();
    foreach (array(
      'rebase-merge',
      'rebase-apply',
      'MERGE_HEAD',
      'CHERRY_PICK_HEAD',
      'REVERT_HEAD',
      'sequencer',
    ) as $marker) {
      list($path) = $this->api->execxLocal('rev-parse --git-path %s', $marker);
      if (file_exists(Filesystem::resolvePath(trim($path), $this->path))) {
        throw new PhutilArgumentUsageException(
          pht(
            'Finish the current Git operation before requesting '.
            'worktree cleanup.'));
      }
    }
  }

  public function assertSelectedCommits(array $commits) {
    if (!in_array($this->commit, $commits, true)) {
      throw new PhutilArgumentUsageException(
        pht('Worktree cleanup requires landing the current branch tip.'));
    }
  }

  public function execute($landed_commit) {
    $record = $this->getRecord();
    if (isset($record['branch']) || idx($record, 'HEAD') !== $landed_commit) {
      throw new PhutilArgumentUsageException(
        pht(
          'The worktree no longer points at the detached landed '.
          'commit; keeping it.'));
    }
    $this->assertClean();
    list($branch_commit) = $this->api->execxLocal(
      'rev-parse --verify %s',
      'refs/heads/'.$this->branch);
    if (trim($branch_commit) !== $this->commit) {
      throw new PhutilArgumentUsageException(
        pht(
          'The source branch changed during landing; keeping the '.
          'worktree and branch.'));
    }

    foreach (self::readWorktrees($this->api) as $other) {
      if (idx($other, 'branch') === 'refs/heads/'.$this->branch) {
        throw new PhutilArgumentUsageException(
          pht('The source branch is now checked out; keeping it.'));
      }
    }

    // No --force: Git must also accept the current ownership and cleanliness.
    // Leave this process outside the directory before removing it. A child
    // process can not change the invoking shell's working directory.
    if (!chdir($this->commonDirectory)) {
      throw new Exception(pht('Unable to leave the worktree before cleanup.'));
    }
    execx(
      'git --git-dir %s worktree remove -- %s',
      $this->commonDirectory,
      $this->path);

    if (!$this->keepBranch) {
      // Compare-and-delete protects a branch advanced by another process.
      execx(
        'git --git-dir %s update-ref -d %s %s',
        $this->commonDirectory,
        'refs/heads/'.$this->branch,
        $this->commit);
    }
  }

}
