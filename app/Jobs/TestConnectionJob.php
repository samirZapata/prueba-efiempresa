<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TestConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    protected string $testMessage;

    public function __construct(string $message = 'Test job ejecutado')
    {
        $this->testMessage = $message;
    }

    public function handle()
    {
        Log::info("=== TEST JOB INICIADO ===");
        Log::info("Mensaje: " . $this->testMessage);
        
        // Simular trabajo
        sleep(2);
        
        Log::info("=== TEST JOB COMPLETADO ===");
    }
}