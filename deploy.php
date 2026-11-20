<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/common.php';

set('application', 'Apollo');
set('repository', 'git@github.com:jester1979/apollo.git');

// The simulation lives in the PHP session, so a release shares no files or folders.
set('shared_files', []);
set('shared_dirs', []);
set('writable_dirs', []);

set('keep_releases', 5);

// The web root of the domain is a symlink to {{deploy_path}}/current/public.
host('prod')
    ->set('branch', 'main')
    ->set('hostname', 'web1.radishconcepts.com')
    ->set('remote_user', 'apollo')
    ->set('deploy_path', '/home/apollo/domains/apollo.florisplof.nl/deploy');

task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
]);

after('deploy:failed', 'deploy:unlock');
