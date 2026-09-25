<?php

final class ArcanistGitRepositoryMarkerQueryTestCase extends PhutilTestCase {

  public function testRemoteMarkersRequestOnlyBranches() {
    if (!Filesystem::binaryExists('git')) {
      $this->assertSkipped(pht('Git is not installed.'));
    }

    $fixture = PhutilDirectoryFixture::newEmptyFixture();
    $remote = $fixture->getPath('remote');
    $local = $fixture->getPath('local');

    execx('git init -- %s', $remote);
    $origin = new ArcanistGitAPI($remote);
    $origin->execxLocal('config user.name %s', 'Test');
    $origin->execxLocal('config user.email %s', 'test@example.com');
    $origin->execxLocal('config commit.gpgsign false');
    $origin->execxLocal('checkout -b master');
    Filesystem::writeFile($remote.'/file', "base\n");
    $origin->execxLocal('add .');
    $origin->execxLocal('commit -m base');
    $origin->execxLocal('branch feature');
    $origin->execxLocal('tag v1');
    // Stands in for the "refs/pull/*" refs GitHub keeps for every PR.
    $origin->execxLocal('update-ref refs/pull/1/head HEAD');

    execx('git init -- %s', $local);
    $api = new ArcanistGitAPI($local);
    $api->execxLocal('remote add origin %s', 'file://'.$remote);

    $trace = $fixture->getPath('trace');
    $previous = getenv('GIT_TRACE_PACKET');
    putenv('GIT_TRACE_PACKET='.$trace);
    try {
      $remote_ref = $api->newRemoteRefQuery()
        ->withNames(array('origin'))
        ->executeOne();
      $markers = $api->newMarkerRefQuery()
        ->withRemotes(array($remote_ref))
        ->execute();
    } finally {
      putenv(
        ($previous === false) ?
          'GIT_TRACE_PACKET' :
          'GIT_TRACE_PACKET='.$previous);
    }

    $names = mpull($markers, 'getName');
    sort($names);
    $this->assertEqual(array('feature', 'master'), $names);

    // The server, not the client, must do the filtering.
    $this->assertTrue(
      strpos(Filesystem::readFile($trace), 'ref-prefix refs/heads/') !== false);
  }

}
