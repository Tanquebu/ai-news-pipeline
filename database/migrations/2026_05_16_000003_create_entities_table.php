<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE entities ADD CONSTRAINT entities_type_check CHECK (type IN ('company','person','regulation','product','other'))");
        DB::statement('CREATE INDEX entities_lower_name_type_idx ON entities (lower(name), type)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
