<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ad_script_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('reference_script');
            $table->text('outcome_description');
            $table->text('new_script')->nullable();
            $table->json('analysis')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending');
            $table->text('error_details')->nullable();
            $table->timestamps();

            // Add indexes for performance
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_script_tasks');
    }
};
