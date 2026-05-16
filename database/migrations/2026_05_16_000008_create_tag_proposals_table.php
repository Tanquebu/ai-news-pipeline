<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tag_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->text('reason');
            $table->unsignedInteger('frequency')->default(1);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE tag_proposals ADD CONSTRAINT tag_proposals_status_check CHECK (status IN ('pending','approved','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_proposals');
    }
};
