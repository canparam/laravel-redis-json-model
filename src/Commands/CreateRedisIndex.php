<?php

namespace Ken\Commands;

use Illuminate\Console\Command;
use Ken\Models\Model;

class CreateRedisIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create-index {--class=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $class = $this->option('class', '');
        if (empty($class)) {
            $this->error("Class Cant Not Empty!");
            return 0;
        }
        if (!class_exists($class)) {
            // Class tồn tại
            $this->error("Class Not Found");
            return 0;
        }
        try {
            $model = new $class();
            $dataType = $model->getFielDataType();
            $dbName = env('REDIS_JSON_NAME', 'database');
            $prefix = "{$dbName}:{$model->prefix()}:";

            $indexName = "{$dbName}-{$model->prefix()}-idx";

            $command = "FT.CREATE";

            $args = [
                $indexName,
                "ON",
                "JSON",
                "PREFIX",
                "1",
                $prefix,
                "SCHEMA"
            ];
            // SCHEMA $.question as question TEXT
            foreach ($dataType as $k => $value) {
                if ($value['index'] ?? false == true) {
                    $args[] = "$.{$k}";
                    $args[] = "as";
                    $args[] = "{$k}";
                    $args[] = "{$value['type']}";
                }

            }
            $client = new Model();
            $client->getConnection()->raw('ft.dropIndex', $indexName);
            $client = $client->getConnection()->raw($command, ...$args);
            $this->info("Create Index Done");
        }catch (\Exception $exception){
            throw new \Exception($exception->getMessage());
        }
        return 0;

    }
}
