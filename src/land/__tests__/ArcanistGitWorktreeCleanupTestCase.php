<?php

final class ArcanistGitWorktreeCleanupTestCase extends PhutilTestCase {

  private function newRepository() {
    if (!Filesystem::binaryExists('git')) {
      $this->assertSkipped(pht('Git is not installed.'));
    }
    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $main = $fixture->getPath('main');
    execx('git init -- %s', $main);
    $api = new ArcanistGitAPI($main);
    $api->execxLocal('config user.name %s', 'Test');
    $api->execxLocal('config user.email %s', 'test@example.com');
    $api->execxLocal('config commit.gpgsign false');
    $api->execxLocal('checkout -b master');
    Filesystem::writeFile($main.'/file', "base\n");
    Filesystem::writeFile($main.'/.gitignore', "ignored\n");
    $api->execxLocal('add .');
    $api->execxLocal('commit -m base');
    $path = $fixture->getPath("linked space\nnewline");
    $api->execxLocal('worktree add -b feature -- %s master', $path);
    return array($fixture, $api, new ArcanistGitAPI($path));
  }

  private function newPlan(ArcanistGitAPI $api, $keep = false) {
    return ArcanistGitWorktreeCleanup::newPlan($api, $keep);
  }

  public function testCleanupAndKeepBranch() {
    foreach (array(false, true) as $keep) {
      list($fixture, $main, $api) = $this->newRepository();
      $other = $fixture->getPath('other');
      $main->execxLocal('worktree add -b other -- %s master', $other);
      list($head) = $api->execxLocal('rev-parse HEAD');
      $head = trim($head);
      $plan = $this->newPlan($api, $keep);
      $plan->assertSelectedCommits(array($head));
      $this->assertTrue(is_dir($api->getPath()));
      Filesystem::writeFile($api->getPath('ignored'), 'ignored data');
      $api->execxLocal('checkout --detach %s --', $head);
      $cwd = getcwd();
      try {
        $plan->execute($head);
      } finally {
        chdir($cwd);
      }
      $this->assertFalse(is_dir($api->getPath()));
      list($err) = $main->execManualLocal(
        'show-ref --verify refs/heads/feature');
      $this->assertEqual($keep, !$err);
      $this->assertTrue(is_dir($other));
      list($main_head) = $main->execxLocal('rev-parse HEAD');
      $this->assertEqual($head, trim($main_head));
      $this->assertEqual(2, count(
        ArcanistGitWorktreeCleanup::readWorktrees($main)));
    }
  }

  public function testRejectUnsafeCheckouts() {
    foreach (array(
      'primary',
      'detached',
      'locked',
      'dirty',
      'untracked',
      'operation',
      'submodule',
    ) as $case) {
      list($fixture, $main, $api) = $this->newRepository();
      switch ($case) {
        case 'primary':
          $api = $main;
          break;
        case 'detached':
          $api->execxLocal('checkout --detach HEAD');
          break;
        case 'locked':
          $main->execxLocal('worktree lock -- %s', $api->getPath());
          break;
        case 'dirty':
          Filesystem::writeFile($api->getPath('file'), 'changed');
          break;
        case 'untracked':
          Filesystem::writeFile($api->getPath('new'), 'new');
          break;
        case 'operation':
          list($path) = $api->execxLocal('rev-parse --git-path MERGE_HEAD');
          Filesystem::writeFile(trim($path), 'in progress');
          break;
        case 'submodule':
          list($head) = $api->execxLocal('rev-parse HEAD');
          $api->execxLocal(
            'update-index --add --cacheinfo 160000 %s sub', trim($head));
          $api->execxLocal('commit -m submodule');
          break;
      }
      $this->assertException(
        'PhutilArgumentUsageException',
        function () use ($api) {
          ArcanistGitWorktreeCleanup::newPlan($api, false);
        });
      $this->assertTrue(is_dir($api->getPath()), $case);
    }
  }

  public function testRejectChangedState() {
    $cases = array('tip', 'branch', 'dirty', 'lock', 'attached', 'adopted');
    foreach ($cases as $case) {
      list($fixture, $main, $api) = $this->newRepository();
      $plan = $this->newPlan($api);
      list($head) = $api->execxLocal('rev-parse HEAD');
      $head = trim($head);
      $this->assertException(
        'PhutilArgumentUsageException',
        function () use ($plan) {
          $plan->assertSelectedCommits(array());
        });
      if ($case !== 'attached') {
        $api->execxLocal('checkout --detach HEAD');
      }
      switch ($case) {
        case 'tip':
          $api->execxLocal('commit --allow-empty -m moved');
          break;
        case 'branch':
          $api->execxLocal('commit --allow-empty -m moved');
          $api->execxLocal('branch -f feature HEAD');
          $api->execxLocal('checkout --detach %s', $head);
          break;
        case 'dirty':
          Filesystem::writeFile($api->getPath('new'), 'keep me');
          break;
        case 'adopted':
          $main->execxLocal(
            'worktree add -- %s feature', $fixture->getPath('adopted'));
          break;
        case 'lock':
          $main->execxLocal('worktree lock -- %s', $api->getPath());
          break;
      }
      $this->assertException('PhutilArgumentUsageException',
        function () use ($plan, $head) {
          $plan->execute($head);
        });
      $this->assertTrue(is_dir($api->getPath()), $case);
      list($err) = $main->execManualLocal(
        'show-ref --verify refs/heads/feature');
      $this->assertEqual(0, $err, $case);
    }
  }

  public function testReadinessRechecksBranch() {
    list($fixture, $main, $api) = $this->newRepository();
    $plan = $this->newPlan($api);
    $api->execxLocal('commit --allow-empty -m changed');
    $this->assertException(
      'PhutilArgumentUsageException',
      function () use ($plan) {
        $plan->assertReady();
      });
  }

  public function testPruningPreservesBranchesInOtherWorktrees() {
    list($fixture, $main, $api) = $this->newRepository();
    $other = $fixture->getPath('other');
    $main->execxLocal('worktree add -b other -- %s master', $other);
    list($head) = $api->execxLocal('rev-parse HEAD');
    $commit = id(new ArcanistLandCommit())->setHash(trim($head));
    $revision = ArcanistRevisionRef::newFromConduit(array('phid' => 'test'));
    $set = id(new ArcanistLandCommitSet())
      ->setRevisionRef($revision)
      ->setCommits(array($commit));
    $engine = id(new ArcanistGitLandEngine())
      ->setRepositoryAPI($api)
      ->setLogEngine(new ArcanistLogEngine());
    $api->execxLocal('checkout --detach HEAD');
    $method = new ReflectionMethod($engine, 'pruneBranches');
    if (PHP_VERSION_ID < 80100) {
      $method->setAccessible(true);
    }
    $method->invoke($engine, array($set));
    foreach (array('master', 'other') as $branch) {
      list($actual) = $main->execxLocal('rev-parse %s', $branch);
      $this->assertEqual($head, $actual);
    }
    list($err) = $main->execManualLocal(
      'show-ref --verify refs/heads/feature');
    $this->assertTrue((bool)$err);
  }

  public function testPlainLandReconciliationWithOccupiedMaster() {
    list($fixture, $main, $api) = $this->newRepository();
    $remote = $fixture->getPath('remote.git');
    execx('git init --bare -- %s', $remote);
    $main->execxLocal('remote add origin %s', $remote);
    $main->execxLocal('push -u origin master');
    $api->execxLocal('branch --set-upstream-to origin/master feature');
    list($original) = $main->execxLocal('rev-parse HEAD');
    $api->execxLocal('commit --allow-empty -m landed');
    list($landed) = $api->execxLocal('rev-parse HEAD');
    $landed = trim($landed);

    $log = new ArcanistLogEngine();
    $runtime = new ArcanistRuntime();
    $property = new ReflectionProperty('ArcanistRuntime', 'logEngine');
    if (PHP_VERSION_ID < 80100) {
      $property->setAccessible(true);
    }
    $property->setValue($runtime, $log);
    $workflow = id(new ArcanistLandWorkflow())->setRuntime($runtime);
    $state = $api->newLocalState()->setWorkflow($workflow)->saveLocalState();
    $engine = id(new ArcanistGitLandEngine())
      ->setRepositoryAPI($api)
      ->setWorkflow($workflow)
      ->setLogEngine($log)
      ->setIntoRef('master')
      ->setOntoRefs(array('master'))
      ->setOntoRemote('origin');

    // Simulate successful publication and the normal deletion of the source
    // branch before reconciliation. No Phabricator or real remote is involved.
    $api->execxLocal('push origin HEAD:master');
    $api->execxLocal('checkout --detach HEAD');
    $api->execxLocal('branch -D feature');
    $method = new ReflectionMethod($engine, 'reconcileLocalState');
    if (PHP_VERSION_ID < 80100) {
      $method->setAccessible(true);
    }
    $method->invoke($engine, $landed, $state);
    list($head) = $main->execxLocal('rev-parse HEAD');
    $this->assertEqual($original, $head);
    list($head) = $api->execxLocal('rev-parse HEAD');
    $this->assertEqual($landed, trim($head));
    $this->assertTrue(is_dir($api->getPath()));
  }

}
