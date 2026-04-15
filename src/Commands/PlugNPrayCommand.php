<?php

namespace Blax\Workkit\Commands;

use Illuminate\Console\Command;

class PlugNPrayCommand extends Command
{
    protected $signature = 'workkit:plug-n-pray
        {--php=8.4 : PHP version for the Docker image}
        {--name= : Project name (default: directory name)}
        {--host= : Traefik hostname (default: name.localhost)}
        {--db= : Database name (default: project name)}
        {--db-pass=secret : MySQL root password}
        {--image=blaxsoftware/laravel : Docker image name}
        {--no-queue : Disable queue worker}
        {--no-scheduler : Disable scheduler}
        {--horizon : Enable Horizon (disables basic queue)}
        {--no-redis : Skip Redis service}
        {--no-mysql : Skip MySQL service}
        {--websocket : Enable WebSocket server (blax/laravel-websockets)}
        {--websocket-port=6001 : WebSocket server port}
        {--force : Overwrite existing files}';

    protected $description = 'Generate a Docker Compose boilerplate for this Laravel project (plug-n-pray)';

    public function handle(): int
    {
        $basePath = base_path();

        $phpVersion = $this->option('php');
        $image = $this->option('image');
        $force = $this->option('force');

        $projectName = $this->option('name')
            ?: strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', basename($basePath)));
        $projectName = trim(preg_replace('/--+/', '-', $projectName), '-');

        $traefikHost = $this->option('host') ?: "{$projectName}.localhost";
        $dbName = $this->option('db') ?: str_replace('-', '_', $projectName);
        $dbPassword = $this->option('db-pass');

        $enableQueue = ! $this->option('no-queue');
        $enableScheduler = ! $this->option('no-scheduler');
        $enableHorizon = (bool) $this->option('horizon');
        $enableRedis = ! $this->option('no-redis');
        $enableMysql = ! $this->option('no-mysql');
        $enableWebsocket = (bool) $this->option('websocket');
        $websocketPort = $this->option('websocket-port');

        if ($enableHorizon) {
            $enableQueue = false;
        }

        // Safety check
        if (! $force && file_exists("{$basePath}/docker-compose.yml")) {
            $this->error('docker-compose.yml already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        $this->info('');
        $this->info('==========================================');
        $this->info('  plug-n-pray 🙏');
        $this->info('==========================================');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Project', $projectName],
                ['PHP', $phpVersion],
                ['Image', "{$image}:php{$phpVersion}"],
                ['Traefik host', $traefikHost],
                ['MySQL', $enableMysql ? "Yes ({$dbName})" : 'No'],
                ['Redis', $enableRedis ? 'Yes' : 'No'],
                ['Queue', $enableQueue ? 'Yes' : 'No'],
                ['Scheduler', $enableScheduler ? 'Yes' : 'No'],
                ['Horizon', $enableHorizon ? 'Yes' : 'No'],
                ['WebSocket', $enableWebsocket ? "Yes (port {$websocketPort})" : 'No'],
            ]
        );

        if (! $force && ! $this->confirm('Generate Docker files with these settings?', true)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // Create supervisor directory
        if (! is_dir("{$basePath}/docker/supervisor")) {
            mkdir("{$basePath}/docker/supervisor", 0755, true);
        }

        // Generate docker-compose.yml
        $compose = $this->generateCompose(
            $projectName,
            $phpVersion,
            $image,
            $traefikHost,
            $dbName,
            $dbPassword,
            $enableQueue,
            $enableScheduler,
            $enableHorizon,
            $enableRedis,
            $enableMysql,
            $enableWebsocket,
            $websocketPort
        );
        file_put_contents("{$basePath}/docker-compose.yml", $compose);

        // Generate .env.docker
        $envDocker = $this->generateEnvDocker(
            $traefikHost,
            $dbName,
            $dbPassword,
            $enableRedis,
            $enableMysql,
            $enableWebsocket,
            $websocketPort
        );
        file_put_contents("{$basePath}/.env.docker", $envDocker);

        // Generate websocket supervisor config
        if ($enableWebsocket) {
            $wsConf = <<<CONF
[program:websocket]
command=/usr/local/bin/php -d variables_order=EGPCS /var/www/html/artisan websockets:serve --host=0.0.0.0 --port={$websocketPort}
autostart=true
autorestart=true
user=www-data
priority=30
startsecs=5
startretries=100
stopsignal=TERM
stopwaitsecs=15
stdout_logfile=/proc/1/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/proc/1/fd/2
stderr_logfile_maxbytes=0
CONF;
            file_put_contents("{$basePath}/docker/supervisor/websocket.conf", $wsConf);
        }

        $this->info('');
        $this->info('Files created:');
        $this->line('  docker-compose.yml');
        $this->line('  .env.docker');
        $this->line('  docker/supervisor/');
        if ($enableWebsocket) {
            $this->line('  docker/supervisor/websocket.conf');
        }
        $this->info('');
        $this->info('Next steps:');
        $this->line("  1. Merge .env.docker into your .env");
        $this->line("  2. Create the network (once): docker network create web");
        $this->line("  3. Start: docker compose up -d");
        $this->line("  4. Visit: http://{$traefikHost}");
        $this->info('');
        $this->info('Pray it works. 🙏');

        return self::SUCCESS;
    }

    private function generateCompose(
        string $name,
        string $php,
        string $image,
        string $host,
        string $dbName,
        string $dbPassword,
        bool $queue,
        bool $scheduler,
        bool $horizon,
        bool $redis,
        bool $mysql,
        bool $websocket = false,
        string $websocketPort = '6001'
    ): string {
        $enableQueue = $queue ? 'true' : 'false';
        $enableScheduler = $scheduler ? 'true' : 'false';
        $enableHorizon = $horizon ? 'true' : 'false';

        $yaml = <<<YAML
# Generated by workkit:plug-n-pray
networks:
  web:
    external: true
  internal:
    driver: bridge

YAML;

        // Volumes
        $volumes = [];
        if ($mysql) {
            $volumes[] = '  mysql-data:';
        }
        if ($redis) {
            $volumes[] = '  redis-data:';
        }
        if (! empty($volumes)) {
            $yaml .= "volumes:\n" . implode("\n", $volumes) . "\n\n";
        }

        // App service
        $depends = '';
        if ($mysql || $redis) {
            $depLines = [];
            if ($mysql) {
                $depLines[] = "      mysql:\n        condition: service_healthy";
            }
            if ($redis) {
                $depLines[] = "      redis:\n        condition: service_healthy";
            }
            $depends = "    depends_on:\n" . implode("\n", $depLines) . "\n";
        }

        $yaml .= <<<YAML
services:
  app:
    image: {$image}:php{$php}
    container_name: {$name}-app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./:/var/www/html
      - ./docker/supervisor:/etc/supervisor/custom.d
    environment:
      ENABLE_QUEUE: "{$enableQueue}"
      ENABLE_SCHEDULER: "{$enableScheduler}"
      ENABLE_HORIZON: "{$enableHorizon}"
      ENABLE_LARAVEL_PERMS: "1"
YAML;

        if ($websocket) {
            $yaml .= "      PUSHER_PORT: \"{$websocketPort}\"\n";
        }

        $yaml .= <<<YAML
    networks:
      - web
      - internal
{$depends}    labels:
      - traefik.enable=true
      - traefik.docker.network=web
      - traefik.http.routers.{$name}.rule=Host(`{$host}`)
      - traefik.http.routers.{$name}.entrypoints=web
      - traefik.http.routers.{$name}.service={$name}-http
      - traefik.http.services.{$name}-http.loadbalancer.server.port=80
      - traefik.http.routers.{$name}-tls.rule=Host(`{$host}`)
      - traefik.http.routers.{$name}-tls.entrypoints=websecure
      - traefik.http.routers.{$name}-tls.tls=true
      - traefik.http.routers.{$name}-tls.service={$name}-https
      - traefik.http.services.{$name}-https.loadbalancer.server.port=80
YAML;

        if ($websocket) {
            $yaml .= <<<YAML
      # WebSocket
      - traefik.http.routers.{$name}-ws.rule=Host(`ws-{$host}`)
      - traefik.http.routers.{$name}-ws.entrypoints=web
      - traefik.http.routers.{$name}-ws.service={$name}-ws
      - traefik.http.services.{$name}-ws.loadbalancer.server.port={$websocketPort}
      - traefik.http.routers.{$name}-wss.rule=Host(`ws-{$host}`)
      - traefik.http.routers.{$name}-wss.entrypoints=websecure
      - traefik.http.routers.{$name}-wss.tls=true
      - traefik.http.routers.{$name}-wss.service={$name}-wss
      - traefik.http.services.{$name}-wss.loadbalancer.server.port={$websocketPort}
YAML;
        }

        $yaml .= "\n";

        // MySQL
        if ($mysql) {
            $yaml .= <<<YAML

  mysql:
    image: mysql:8.0
    container_name: {$name}-mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: "{$dbPassword}"
      MYSQL_DATABASE: "{$dbName}"
    volumes:
      - mysql-data:/var/lib/mysql
    networks:
      - internal
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-p{$dbPassword}"]
      interval: 10s
      timeout: 5s
      retries: 5

YAML;
        }

        // Redis
        if ($redis) {
            $yaml .= <<<YAML

  redis:
    image: redis:7-alpine
    container_name: {$name}-redis
    restart: unless-stopped
    volumes:
      - redis-data:/data
    networks:
      - internal
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

YAML;
        }

        return $yaml;
    }

    private function generateEnvDocker(
        string $host,
        string $dbName,
        string $dbPassword,
        bool $redis,
        bool $mysql,
        bool $websocket = false,
        string $websocketPort = '6001'
    ): string {
        $env = "# Generated by workkit:plug-n-pray\n\nAPP_URL=http://{$host}\n";

        if ($mysql) {
            $env .= <<<ENV

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE={$dbName}
DB_USERNAME=root
DB_PASSWORD={$dbPassword}
ENV;
        }

        if ($redis) {
            $env .= <<<ENV

REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
ENV;
        }

        if ($websocket) {
            $env .= <<<ENV

BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=app-key
PUSHER_APP_SECRET=app-secret
PUSHER_HOST=127.0.0.1
PUSHER_PORT={$websocketPort}
PUSHER_SCHEME=http
LARAVEL_WEBSOCKETS_PORT={$websocketPort}
ENV;
        }

        return $env . "\n";
    }
}
