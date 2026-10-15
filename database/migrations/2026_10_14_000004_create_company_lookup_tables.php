<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company lookup lists on Company Setup → Operation Setup. They share one shape;
     * removing an entry sets deleted_at, and it can be restored later.
     *
     * @var list<string>
     */
    private array $tables = ['account_types', 'meter_types', 'cluster_types', 'departments'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('company_profiles')->cascadeOnDelete();
                $table->string('name');
                // Departments don't show this in the UI.
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'name']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        foreach (array_reverse($this->tables) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
