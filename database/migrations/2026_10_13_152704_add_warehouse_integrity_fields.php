<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('stock_version')->default(0);
        });
        Schema::table('batches', function (Blueprint $table): void {
            $table->string('location', 100)->default('Main warehouse');
            $table->foreignId('origin_batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->index(['origin_batch_id', 'location']);
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->unsignedInteger('stock_before')->nullable();
            $table->unsignedInteger('stock_after')->nullable();
            $table->unsignedInteger('batch_before')->nullable();
            $table->unsignedInteger('batch_after')->nullable();
            $table->foreignId('destination_batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->string('source_location', 100)->nullable();
            $table->string('destination_location', 100)->nullable();
            $table->string('return_condition', 100)->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->unique(['user_id', 'request_id']);
        });
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->unsignedBigInteger('stock_version')->nullable();
            $table->json('batch_counts')->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->unique(['requested_by', 'request_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropUnique(['requested_by', 'request_id']);
            $table->dropColumn(['stock_version', 'batch_counts', 'request_id', 'request_fingerprint']);
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'request_id']);
            $table->dropConstrainedForeignId('destination_batch_id');
            $table->dropColumn(['stock_before', 'stock_after', 'batch_before', 'batch_after', 'source_location', 'destination_location', 'return_condition', 'request_id', 'request_fingerprint']);
        });
        Schema::table('batches', function (Blueprint $table): void {
            $table->dropIndex(['origin_batch_id', 'location']);
            $table->dropConstrainedForeignId('origin_batch_id');
            $table->dropColumn('location');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('stock_version');
        });
    }
};
