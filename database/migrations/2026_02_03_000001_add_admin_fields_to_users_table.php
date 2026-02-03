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
        Schema::table('users', function (Blueprint $table) {
            // Dodaj 'role' kolonu ako ne postoji
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('user')->after('password');
            }
            
            // Dodaj 'status' kolonu ako ne postoji
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('password');
            }
            
            // Dodaj 'last_seen' kolonu ako ne postoji
            if (!Schema::hasColumn('users', 'last_seen')) {
                $table->timestamp('last_seen')->nullable()->after('updated_at');
            }
            
            // Dodaj 'is_verified' kolonu ako ne postoji
            if (!Schema::hasColumn('users', 'is_verified')) {
                // Provjeri da li postoji email_verified_at prije nego što referenciram
                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $table->boolean('is_verified')->default(false)->after('email_verified_at');
                } else {
                    $table->boolean('is_verified')->default(false)->after('email');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Obriši kolone koje smo dodali
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('users', 'last_seen')) {
                $table->dropColumn('last_seen');
            }
            if (Schema::hasColumn('users', 'is_verified')) {
                $table->dropColumn('is_verified');
            }
        });
    }
};