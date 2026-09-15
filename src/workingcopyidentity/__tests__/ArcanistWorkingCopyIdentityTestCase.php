<?php

final class ArcanistWorkingCopyIdentityTestCase extends PhutilTestCase {

  public function testGitWorktreeLocalConfigPath() {
    $root = Filesystem::createTemporaryDirectory();
    $gitdir = $root.'/gitdir';
    Filesystem::createDirectory($gitdir);
    Filesystem::writeFile($root.'/.arcconfig', "{}\n");
    Filesystem::writeFile($root.'/.git', "gitdir: gitdir\n");

    $identity = ArcanistWorkingCopyIdentity::newFromPath($root);
    $identity->writeLocalArcConfig(array('test.key' => 'value'));

    $this->assertTrue(Filesystem::pathExists($gitdir.'/arc/config'));
    $this->assertEqual(
      array('test.key' => 'value'),
      phutil_json_decode(Filesystem::readFile($gitdir.'/arc/config')));
  }

}
