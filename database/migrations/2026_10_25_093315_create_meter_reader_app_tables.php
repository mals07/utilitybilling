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
        // Meter reader app settings on Company Setup → Operation Setup.
        Schema::table('company_profiles', function (Blueprint $table) {
            // Extra invoice numbers reserved for a device above its route's unread meters, in percent.
            $table->unsignedSmallInteger('reader_block_margin')->default(10)->after('review_zero_usage');
            // How far a device's clock may be from the server's before its readings are held.
            $table->unsignedSmallInteger('reader_clock_drift_minutes')->default(10)->after('reader_block_margin');
            // How far from the customer's map location a reading may be taken; null turns the check off.
            $table->unsignedInteger('reader_gps_limit_meters')->nullable()->after('reader_clock_drift_minutes');
            // Whether a reader may confirm a reading that needs review at the meter and print its bill.
            $table->boolean('readers_confirm_flagged')->default(true)->after('reader_gps_limit_meters');
        });

        // A phone registered to a meter reader. Only the SHA-256 hash of its API token is stored.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('token_expires_at');
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Which reader reads a cluster in a billing period. One reader per cluster per period.
        Schema::create('reading_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->string('billing_period', 7);
            $table->foreignId('cluster_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Named: the generated name is longer than MySQL allows.
            $table->unique(['company_id', 'billing_period', 'cluster_type_id'], 'reading_assignments_period_cluster_unique');
            $table->index(['user_id', 'billing_period']);
        });

        // What a device downloaded to read its route offline: when, and each customer's version then.
        Schema::create('route_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('billing_period', 7);
            $table->timestamp('as_of');
            // Hash of the billing settings, rates and charges in the package (App\Support\Reader\RoutePackages).
            $table->string('config_hash', 64);
            // {customer id: version hash} of the customers in the package.
            $table->json('customer_versions');
            $table->timestamps();

            $table->index(['device_id', 'billing_period']);
        });

        // Invoice numbers reserved for a device to print bills with offline.
        Schema::create('invoice_number_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('first_number');
            $table->unsignedBigInteger('last_number');
            // Set once the device has handed back the numbers it didn't use.
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'closed_at']);
        });

        // Reserved invoice numbers that never became an invoice, so every number is accounted for.
        Schema::create('invoice_number_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('invoice_number_block_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 20);
            // A value of App\Enums\NumberGapReason.
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);
        });

        // One upload from a device. Nothing in it is applied until every item has arrived.
        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('item_count');
            // SHA-256 of the batch's item uuids, sorted and joined by new lines.
            $table->string('checksum', 64);
            // Every item uuid uploaded in the batch, including ones a previous batch already delivered.
            $table->json('received_uuids');
            $table->timestamp('device_time');
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });

        // A reading (with the bill printed for it) or a skipped invoice number recorded on a device.
        Schema::create('sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('sync_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_uuid');
            // A value of App\Enums\SyncItemType.
            $table->string('type', 20);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_period', 7)->nullable();
            $table->string('invoice_number', 20)->nullable();
            $table->json('payload');
            // A value of App\Enums\SyncItemStatus.
            $table->string('status', 20)->default('received');
            // How a supervisor resolved a held item: a value of App\Enums\SyncResolution.
            $table->string('resolution', 20)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('meter_reading_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'client_uuid']);
            $table->index(['company_id', 'status']);
            $table->index(['customer_id', 'billing_period', 'status']);
        });

        // What the server found different from what the device recorded, for one item.
        Schema::create('sync_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->foreignId('sync_item_id')->constrained()->cascadeOnDelete();
            // A value of App\Enums\SyncCheck.
            $table->string('check', 30);
            // A value of App\Enums\DiscrepancySeverity.
            $table->string('severity', 10);
            $table->string('message', 500);
            $table->string('device_value', 500)->nullable();
            $table->string('server_value', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('created_by_id')->constrained()->nullOnDelete();
            // When the reading was taken on the device, by the device's clock.
            $table->timestamp('read_at')->nullable()->after('read_on');
            $table->double('latitude')->nullable()->after('read_at');
            $table->double('longitude')->nullable()->after('latitude');
            $table->string('photo_path')->nullable()->after('longitude');
            // The reader confirmed a reading that needs review at the meter (re-entered it and took a photo).
            $table->boolean('confirmed_by_reader')->default(false)->after('confirmed_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('created_by_id')->constrained()->nullOnDelete();
            // When the device printed the bill at the meter.
            $table->timestamp('printed_at')->nullable()->after('device_id');
            // Issued with the server's figures in place of the bill the device printed.
            $table->boolean('is_corrected')->default(false)->after('printed_at');
        });
    }

    /**
     * Reverse the migrations. Drops everything devices uploaded.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn(['printed_at', 'is_corrected']);
        });
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn(['read_at', 'latitude', 'longitude', 'photo_path', 'confirmed_by_reader']);
        });

        Schema::dropIfExists('sync_discrepancies');
        Schema::dropIfExists('sync_items');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('invoice_number_gaps');
        Schema::dropIfExists('invoice_number_blocks');
        Schema::dropIfExists('route_packages');
        Schema::dropIfExists('reading_assignments');
        Schema::dropIfExists('devices');

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn(['reader_block_margin', 'reader_clock_drift_minutes', 'reader_gps_limit_meters', 'readers_confirm_flagged']);
        });
    }
};
