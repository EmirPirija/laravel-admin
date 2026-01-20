<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =============================================
        // 📊 GLAVNA TABELA ZA DNEVNU STATISTIKU
        // =============================================
        Schema::create('item_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->date('date')->index();
            
            // ═══════════════════════════════════════════
            // 👁️ PREGLEDI
            // ═══════════════════════════════════════════
            $table->unsignedInteger('views')->default(0)->comment('Ukupno pregleda');
            $table->unsignedInteger('unique_views')->default(0)->comment('Jedinstveni posjetioci');
            $table->unsignedInteger('returning_views')->default(0)->comment('Povratne posjete');
            $table->unsignedInteger('avg_time_on_page')->default(0)->comment('Prosječno vrijeme (sekunde)');
            $table->unsignedInteger('bounce_count')->default(0)->comment('Napustili bez interakcije');
            
            // ═══════════════════════════════════════════
            // ❤️ FAVORITI / SAČUVANO
            // ═══════════════════════════════════════════
            $table->unsignedInteger('favorites_added')->default(0)->comment('Dodato u favorite');
            $table->unsignedInteger('favorites_removed')->default(0)->comment('Uklonjeno iz favorita');
            $table->unsignedInteger('favorites_net')->default(0)->comment('Neto promjena favorita');
            
            // ═══════════════════════════════════════════
            // 💬 KONTAKT / KOMUNIKACIJA
            // ═══════════════════════════════════════════
            $table->unsignedInteger('messages_started')->default(0)->comment('Novih konverzacija');
            $table->unsignedInteger('messages_total')->default(0)->comment('Ukupno poruka');
            $table->unsignedInteger('phone_reveals')->default(0)->comment('Otkrivanja broja telefona');
            $table->unsignedInteger('phone_clicks')->default(0)->comment('Klik na pozovi');
            $table->unsignedInteger('whatsapp_clicks')->default(0)->comment('Klik na WhatsApp');
            $table->unsignedInteger('viber_clicks')->default(0)->comment('Klik na Viber');
            $table->unsignedInteger('email_clicks')->default(0)->comment('Klik na email');
            $table->unsignedInteger('offers_received')->default(0)->comment('Primljenih ponuda');
            $table->decimal('offers_avg_amount', 12, 2)->nullable()->comment('Prosječan iznos ponude');
            
            // ═══════════════════════════════════════════
            // 📤 DIJELJENJE
            // ═══════════════════════════════════════════
            $table->unsignedInteger('shares_total')->default(0)->comment('Ukupno dijeljenja');
            $table->unsignedInteger('share_facebook')->default(0);
            $table->unsignedInteger('share_messenger')->default(0);
            $table->unsignedInteger('share_instagram')->default(0);
            $table->unsignedInteger('share_viber')->default(0);
            $table->unsignedInteger('share_whatsapp')->default(0);
            $table->unsignedInteger('share_twitter')->default(0);
            $table->unsignedInteger('share_linkedin')->default(0);
            $table->unsignedInteger('share_telegram')->default(0);
            $table->unsignedInteger('share_email')->default(0);
            $table->unsignedInteger('share_sms')->default(0);
            $table->unsignedInteger('share_copy_link')->default(0);
            $table->unsignedInteger('share_qr_code')->default(0);
            $table->unsignedInteger('share_print')->default(0);
            
            // ═══════════════════════════════════════════
            // 🖼️ ENGAGEMENT SA SADRŽAJEM
            // ═══════════════════════════════════════════
            $table->unsignedInteger('gallery_opens')->default(0)->comment('Otvaranja galerije');
            $table->unsignedInteger('image_views')->default(0)->comment('Pregleda pojedinačnih slika');
            $table->unsignedInteger('image_zooms')->default(0)->comment('Zumiranja slika');
            $table->unsignedInteger('image_downloads')->default(0)->comment('Preuzimanja slika');
            $table->unsignedInteger('video_plays')->default(0)->comment('Pokretanja videa');
            $table->unsignedInteger('video_completions')->default(0)->comment('Video odgledan do kraja');
            $table->unsignedInteger('video_25_percent')->default(0)->comment('Video 25% odgledan');
            $table->unsignedInteger('video_50_percent')->default(0)->comment('Video 50% odgledan');
            $table->unsignedInteger('video_75_percent')->default(0)->comment('Video 75% odgledan');
            $table->unsignedInteger('description_expands')->default(0)->comment('Klik na "Prikaži više" opis');
            $table->unsignedInteger('specifications_views')->default(0)->comment('Pregleda specifikacija');
            $table->unsignedInteger('location_views')->default(0)->comment('Pregleda lokacije');
            $table->unsignedInteger('map_opens')->default(0)->comment('Otvaranja mape');
            $table->unsignedInteger('map_directions')->default(0)->comment('Klik na upute do lokacije');
            $table->unsignedInteger('seller_profile_clicks')->default(0)->comment('Klik na profil prodavača');
            $table->unsignedInteger('seller_other_items_clicks')->default(0)->comment('Klik na druge oglase prodavača');
            $table->unsignedInteger('similar_items_clicks')->default(0)->comment('Klik na slične oglase');
            $table->unsignedInteger('price_history_views')->default(0)->comment('Pregleda historije cijena');
            
            // ═══════════════════════════════════════════
            // 🔍 PRETRAGA I DISCOVERY
            // ═══════════════════════════════════════════
            $table->unsignedBigInteger('search_impressions')->default(0)->comment('Prikazivanja u pretrazi');
            $table->unsignedInteger('search_clicks')->default(0)->comment('Klikova iz pretrage');
            $table->decimal('search_ctr', 5, 2)->default(0)->comment('Click-through rate %');
            $table->decimal('search_position_avg', 6, 2)->nullable()->comment('Prosječna pozicija u pretrazi');
            $table->unsignedInteger('search_position_best')->nullable()->comment('Najbolja pozicija');
            $table->unsignedInteger('category_impressions')->default(0)->comment('Prikazivanja u kategoriji');
            $table->unsignedInteger('category_clicks')->default(0)->comment('Klikova iz kategorije');
            $table->unsignedInteger('homepage_impressions')->default(0)->comment('Prikazivanja na naslovnoj');
            $table->unsignedInteger('homepage_clicks')->default(0)->comment('Klikova sa naslovne');
            
            // ═══════════════════════════════════════════
            // 🚀 PROMOCIJA / FEATURED
            // ═══════════════════════════════════════════
            $table->boolean('was_featured')->default(false)->comment('Da li je bio promovisan');
            $table->string('featured_position')->nullable()->comment('homepage, category, search');
            $table->unsignedInteger('featured_impressions')->default(0)->comment('Prikazivanja kao featured');
            $table->unsignedInteger('featured_clicks')->default(0)->comment('Klikova kao featured');
            $table->decimal('featured_ctr', 5, 2)->default(0)->comment('Featured CTR %');
            
            // ═══════════════════════════════════════════
            // 🌐 IZVORI PROMETA (Traffic Sources)
            // ═══════════════════════════════════════════
            $table->unsignedInteger('source_direct')->default(0)->comment('Direktni pristup / bookmark');
            $table->unsignedInteger('source_internal_search')->default(0)->comment('Interna pretraga');
            $table->unsignedInteger('source_category_browse')->default(0)->comment('Pregledavanje kategorije');
            $table->unsignedInteger('source_featured_section')->default(0)->comment('Istaknuto sekcija');
            $table->unsignedInteger('source_similar_items')->default(0)->comment('Slični oglasi');
            $table->unsignedInteger('source_seller_profile')->default(0)->comment('Profil prodavača');
            $table->unsignedInteger('source_favorites')->default(0)->comment('Iz favorita');
            $table->unsignedInteger('source_notifications')->default(0)->comment('Iz notifikacija');
            $table->unsignedInteger('source_chat')->default(0)->comment('Iz chata');
            $table->unsignedInteger('source_email_campaign')->default(0)->comment('Email kampanja');
            $table->unsignedInteger('source_push_notification')->default(0)->comment('Push notifikacija');
            
            // --- Eksterni izvori ---
            $table->unsignedInteger('source_google_organic')->default(0)->comment('Google organski');
            $table->unsignedInteger('source_google_ads')->default(0)->comment('Google Ads');
            $table->unsignedInteger('source_facebook')->default(0)->comment('Facebook');
            $table->unsignedInteger('source_instagram')->default(0)->comment('Instagram');
            $table->unsignedInteger('source_viber')->default(0)->comment('Viber');
            $table->unsignedInteger('source_whatsapp')->default(0)->comment('WhatsApp');
            $table->unsignedInteger('source_twitter')->default(0)->comment('Twitter/X');
            $table->unsignedInteger('source_tiktok')->default(0)->comment('TikTok');
            $table->unsignedInteger('source_youtube')->default(0)->comment('YouTube');
            $table->unsignedInteger('source_linkedin')->default(0)->comment('LinkedIn');
            $table->unsignedInteger('source_other_external')->default(0)->comment('Ostali eksterni');
            
            // ═══════════════════════════════════════════
            // 📱 UREĐAJI
            // ═══════════════════════════════════════════
            $table->unsignedInteger('device_mobile')->default(0);
            $table->unsignedInteger('device_desktop')->default(0);
            $table->unsignedInteger('device_tablet')->default(0);
            $table->unsignedInteger('device_app_ios')->default(0)->comment('iOS Aplikacija');
            $table->unsignedInteger('device_app_android')->default(0)->comment('Android Aplikacija');
            
            // ═══════════════════════════════════════════
            // 🌍 GEOGRAFIJA POSJETILACA
            // ═══════════════════════════════════════════
            $table->json('geo_countries')->nullable()->comment('Zemlje posjetilaca {BA: 50, HR: 20}');
            $table->json('geo_cities')->nullable()->comment('Gradovi posjetilaca');
            
            // ═══════════════════════════════════════════
            // ⏰ VRIJEME (Kada gledaju)
            // ═══════════════════════════════════════════
            $table->json('hourly_views')->nullable()->comment('Pregledi po satima {0: 5, 1: 3, ...}');
            
            // ═══════════════════════════════════════════
            // 💰 KONVERZIJA
            // ═══════════════════════════════════════════
            $table->boolean('was_sold')->default(false)->comment('Prodat ovaj dan');
            $table->decimal('sale_price', 12, 2)->nullable()->comment('Prodajna cijena');
            
            // ═══════════════════════════════════════════
            // 🏷️ CIJENA
            // ═══════════════════════════════════════════
            $table->decimal('price_at_date', 12, 2)->nullable()->comment('Cijena na taj dan');
            $table->boolean('price_changed')->default(false)->comment('Cijena promijenjena');
            $table->decimal('price_change_amount', 12, 2)->nullable()->comment('Iznos promjene');
            
            // ═══════════════════════════════════════════
            // 📊 KONKURENCIJA
            // ═══════════════════════════════════════════
            $table->unsignedInteger('category_total_items')->nullable()->comment('Ukupno oglasa u kategoriji');
            $table->unsignedInteger('category_rank')->nullable()->comment('Rang u kategoriji po pregledima');
            $table->decimal('category_percentile', 5, 2)->nullable()->comment('Percentil u kategoriji');
            
            $table->timestamps();
            
            // ═══════════════════════════════════════════
            // 🔑 INDEKSI
            // ═══════════════════════════════════════════
            $table->unique(['item_id', 'date']);
            $table->index(['date', 'views']);
            $table->index(['item_id', 'was_featured']);
            $table->index(['date', 'was_featured']);
            $table->index('created_at');
        });

        // =============================================
        // 📈 TABELA ZA PRAĆENJE VISITOR SESSIONA (detaljno)
        // =============================================
        Schema::create('item_visitor_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->string('visitor_id', 64)->index()->comment('Hashed visitor identifier');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->string('session_id', 64)->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            
            // Izvor
            $table->string('source', 50)->nullable()->comment('direct, search, category, etc');
            $table->string('source_detail')->nullable()->comment('search query, category name, etc');
            $table->string('referrer_url')->nullable();
            $table->string('referrer_domain')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            
            // Uređaj
            $table->string('device_type', 20)->nullable()->comment('mobile, desktop, tablet');
            $table->string('device_os', 30)->nullable()->comment('iOS, Android, Windows, etc');
            $table->string('device_browser', 30)->nullable();
            $table->boolean('is_app')->default(false);
            $table->string('app_version')->nullable();
            
            // Lokacija
            $table->string('country_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            
            // Interakcije u sesiji
            $table->unsignedInteger('page_views')->default(1);
            $table->boolean('viewed_gallery')->default(false);
            $table->boolean('viewed_video')->default(false);
            $table->boolean('clicked_phone')->default(false);
            $table->boolean('clicked_message')->default(false);
            $table->boolean('clicked_share')->default(false);
            $table->boolean('added_favorite')->default(false);
            $table->boolean('made_offer')->default(false);
            $table->boolean('clicked_map')->default(false);
            $table->boolean('clicked_seller')->default(false);
            
            $table->json('actions_log')->nullable()->comment('Detaljan log akcija');
            
            $table->timestamps();
            
            $table->index(['item_id', 'started_at']);
            $table->index(['visitor_id', 'item_id']);
        });

        // =============================================
        // 🔍 TABELA ZA SEARCH IMPRESSIONS
        // =============================================
        Schema::create('item_search_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->string('visitor_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->string('search_query')->nullable();
            $table->string('search_type', 30)->default('general')->comment('general, category, location');
            $table->json('filters_applied')->nullable()->comment('Primijenjeni filteri');
            
            $table->unsignedInteger('position')->comment('Pozicija u rezultatima');
            $table->unsignedInteger('page')->default(1);
            $table->unsignedInteger('results_total')->nullable()->comment('Ukupno rezultata');
            
            $table->boolean('was_clicked')->default(false);
            $table->timestamp('clicked_at')->nullable();
            
            $table->boolean('was_featured')->default(false);
            
            $table->string('device_type', 20)->nullable();
            
            $table->timestamps();
            
            $table->index(['item_id', 'created_at']);
            $table->index(['search_query', 'created_at']);
            $table->index('was_clicked');
        });

        // =============================================
        // 📤 TABELA ZA PRAĆENJE DIJELJENJA
        // =============================================
        Schema::create('item_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('visitor_id', 64)->nullable();
            
            $table->string('platform', 30)->comment('facebook, whatsapp, viber, copy_link, etc');
            $table->string('share_url')->nullable();
            
            // Praćenje da li je neko kliknuo na taj share
            $table->string('share_token', 32)->unique()->nullable();
            $table->unsignedInteger('clicks_from_share')->default(0);
            $table->timestamp('first_click_at')->nullable();
            $table->timestamp('last_click_at')->nullable();
            
            $table->string('device_type', 20)->nullable();
            $table->string('country_code', 2)->nullable();
            
            $table->timestamps();
            
            $table->index(['item_id', 'platform']);
            $table->index('share_token');
        });

        // =============================================
        // 📞 TABELA ZA PRAĆENJE KONTAKTA
        // =============================================
        Schema::create('item_contact_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('visitor_id', 64)->nullable();
            
            $table->string('contact_type', 30)->comment('phone_reveal, phone_click, whatsapp, viber, email, message');
            
            $table->string('device_type', 20)->nullable();
            $table->string('source', 50)->nullable()->comment('Odakle je došao');
            
            $table->timestamps();
            
            $table->index(['item_id', 'contact_type']);
            $table->index(['item_id', 'created_at']);
        });

        // =============================================
        // 💰 DODAJ KOLONE U ITEMS TABELU
        // =============================================
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'total_unique_visitors')) {
                $table->unsignedBigInteger('total_unique_visitors')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_shares')) {
                $table->unsignedInteger('total_shares')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_phone_clicks')) {
                $table->unsignedInteger('total_phone_clicks')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_whatsapp_clicks')) {
                $table->unsignedInteger('total_whatsapp_clicks')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_messages')) {
                $table->unsignedInteger('total_messages')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_gallery_views')) {
                $table->unsignedInteger('total_gallery_views')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_video_plays')) {
                $table->unsignedInteger('total_video_plays')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_search_impressions')) {
                $table->unsignedBigInteger('total_search_impressions')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'total_offers_received')) {
                $table->unsignedInteger('total_offers_received')->default(0)->after('clicks');
            }
            if (!Schema::hasColumn('items', 'conversion_score')) {
                $table->decimal('conversion_score', 5, 2)->default(0)->after('clicks')->comment('Score 0-100');
            }
            if (!Schema::hasColumn('items', 'engagement_score')) {
                $table->decimal('engagement_score', 5, 2)->default(0)->after('clicks')->comment('Score 0-100');
            }
            if (!Schema::hasColumn('items', 'avg_time_on_page')) {
                $table->unsignedInteger('avg_time_on_page')->default(0)->after('clicks')->comment('Sekunde');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_contact_events');
        Schema::dropIfExists('item_shares');
        Schema::dropIfExists('item_search_impressions');
        Schema::dropIfExists('item_visitor_sessions');
        Schema::dropIfExists('item_statistics');
        
        Schema::table('items', function (Blueprint $table) {
            $columns = [
                'total_unique_visitors', 'total_shares', 'total_phone_clicks', 
                'total_whatsapp_clicks', 'total_messages', 'total_gallery_views',
                'total_video_plays', 'total_search_impressions', 'total_offers_received',
                'conversion_score', 'engagement_score', 'avg_time_on_page'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};