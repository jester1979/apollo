<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/common.php';
require 'contrib/cachetool.php';

set('application', 'Apollo');
set('repository', 'git@github.com:jester1979/apollo.git');

// The simulation lives in the PHP session, so a release shares no files or folders.
set('shared_files', []);
set('shared_dirs', []);
set('writable_dirs', []);

set('keep_releases', 5);

// The opcache reset after a deploy goes through the website: the account may not open the PHP-FPM socket.
// cachetool writes a script with a random name into public/, calls it once and removes it.
set('cachetool_args', '--web --web-path=./public --web-url=https://apollo.florisplof.nl');

// The web root of the domain is a symlink to {{deploy_path}}/current/public.
host('prod')
    ->set('branch', 'main')
    ->set('hostname', 'web1.radishconcepts.com')
    ->set('remote_user', 'apollo')
    ->set('deploy_path', '/home/apollo/domains/apollo.florisplof.nl/deploy');

// Without this PHP goes on serving the files of the previous release. The workers keep the resolved path
// of `current` for a day (realpath_cache_ttl), and opcache keeps the compiled scripts under the path through
// the symlink. So: end the workers of this account (the pool starts new ones on the next request), then
// empty opcache, in that order, or an old worker fills opcache with the old release again.
desc('Restarting PHP');
task('restart_php', function (): void {
    run('pkill -u {{remote_user}} -f "^php-fpm: pool {{remote_user}}" || true');
    invoke('cachetool:clear:opcache');
});

task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:symlink',
    'restart_php',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
]);

after('deploy:failed', 'deploy:unlock');
