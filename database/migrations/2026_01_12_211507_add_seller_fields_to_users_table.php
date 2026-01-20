<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Dodaje polja potrebna za Seller Badges sistem:
     * - phone_verified_at: Kada je telefon verificiran
     * - total_sales: Broj uspješnih prodaja (cache)
     * - seller_level: Bronze/Silver/Gold/Platinum (computed cache)
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Verifikacija telefona
            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            }
            
            // Ukupan broj prodaja (incrementira se kad se item označi kao "sold out")
            if (!Schema::hasColumn('users', 'total_sales')) {
                $table->unsignedInteger('total_sales')->default(0)->after('profile');
            }
            
            // Prosječno vrijeme odgovora u minutama (opciono, za "Brzi odgovor" badge)
            if (!Schema::hasColumn('users', 'response_time_avg')) {
                $table->unsignedInteger('response_time_avg')->nullable()->after('total_sales')
                    ->comment('Prosječno vrijeme odgovora u minutama');
            }
            
            // Seller level cache (da se ne računa svaki put)
            if (!Schema::hasColumn('users', 'seller_level')) {
                $table->enum('seller_level', ['bronze', 'silver', 'gold', 'platinum'])
                    ->default('bronze')->after('response_time_avg');
            }
        });
    }
 
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = ['phone_verified_at', 'total_sales', 'response_time_avg', 'seller_level'];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};