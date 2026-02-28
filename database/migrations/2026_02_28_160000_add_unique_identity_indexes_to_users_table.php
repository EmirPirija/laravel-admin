<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function hasIndex(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index]
        );

        return !empty($rows);
    }

    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'email_normalized')) {
                $table->string('email_normalized', 191)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'phone_normalized')) {
                $table->string('phone_normalized', 32)->nullable()->after('mobile');
            }
        });

        DB::table('users')
            ->select(['id', 'email', 'country_code', 'mobile', 'deleted_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $deleted = !empty($row->deleted_at);
                    $rawEmail = trim((string) ($row->email ?? ''));
                    $email = mb_strtolower($rawEmail);
                    $countryCode = preg_replace('/\D+/', '', (string) ($row->country_code ?? '')) ?? '';
                    $mobile = preg_replace('/\D+/', '', (string) ($row->mobile ?? '')) ?? '';
                    $phone = $mobile !== '' ? $countryCode.$mobile : '';

                    $emailForColumn = null;
                    $countryForColumn = null;
                    $mobileForColumn = null;
                    if (!$deleted) {
                        $emailForColumn = $email !== '' ? $email : null;
                        $countryForColumn = $countryCode !== '' ? $countryCode : null;
                        $mobileForColumn = $mobile !== '' ? $mobile : null;
                    } elseif ($rawEmail !== '') {
                        $emailForColumn = "deleted+{$row->id}@deleted.lmx.local";
                    }

                    DB::table('users')
                        ->where('id', $row->id)
                        ->update([
                            'email' => $emailForColumn,
                            'country_code' => $countryForColumn,
                            'mobile' => $mobileForColumn,
                            'email_normalized' => $deleted || $email === '' ? null : $email,
                            'phone_normalized' => $deleted || $phone === '' ? null : $phone,
                        ]);
                }
            }, 'id');

        $duplicateEmailsRaw = DB::table('users')
            ->select('email', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->pluck('email')
            ->toArray();

        if (!empty($duplicateEmailsRaw)) {
            throw new \RuntimeException(
                'Ne mogu dodati unique index za users.email. Prvo riješite duplikate: '.implode(', ', $duplicateEmailsRaw)
            );
        }

        $duplicateEmails = DB::table('users')
            ->select('email_normalized', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->whereNotNull('email_normalized')
            ->groupBy('email_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->pluck('email_normalized')
            ->toArray();

        if (!empty($duplicateEmails)) {
            throw new \RuntimeException(
                'Ne mogu dodati unique index za email. Prvo riješite duplikate: '.implode(', ', $duplicateEmails)
            );
        }

        $duplicatePhones = DB::table('users')
            ->select('phone_normalized', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->whereNotNull('phone_normalized')
            ->groupBy('phone_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->pluck('phone_normalized')
            ->toArray();

        if (!empty($duplicatePhones)) {
            throw new \RuntimeException(
                'Ne mogu dodati unique index za telefon. Prvo riješite duplikate: '.implode(', ', $duplicatePhones)
            );
        }

        $duplicateCountryMobile = DB::table('users')
            ->select('country_code', 'mobile', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->whereNotNull('country_code')
            ->whereNotNull('mobile')
            ->groupBy('country_code', 'mobile')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicateCountryMobile->isNotEmpty()) {
            $pairs = $duplicateCountryMobile
                ->map(fn ($row) => ($row->country_code ?? '').($row->mobile ?? ''))
                ->implode(', ');

            throw new \RuntimeException(
                'Ne mogu dodati unique index za country_code + mobile. Prvo riješite duplikate: '.$pairs
            );
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'country_code')) {
                $table->string('country_code', 8)->nullable()->after('mobile');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!$this->hasIndex('users', 'users_email_unique')) {
                $table->unique('email', 'users_email_unique');
            }

            if (!$this->hasIndex('users', 'users_email_normalized_unique')) {
                $table->unique('email_normalized', 'users_email_normalized_unique');
            }

            if (!$this->hasIndex('users', 'users_phone_normalized_unique')) {
                $table->unique('phone_normalized', 'users_phone_normalized_unique');
            }

            if (!$this->hasIndex('users', 'users_country_code_mobile_unique')) {
                $table->unique(['country_code', 'mobile'], 'users_country_code_mobile_unique');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if ($this->hasIndex('users', 'users_email_unique')) {
                $table->dropUnique('users_email_unique');
            }
            if ($this->hasIndex('users', 'users_country_code_mobile_unique')) {
                $table->dropUnique('users_country_code_mobile_unique');
            }
            if ($this->hasIndex('users', 'users_phone_normalized_unique')) {
                $table->dropUnique('users_phone_normalized_unique');
            }
            if ($this->hasIndex('users', 'users_email_normalized_unique')) {
                $table->dropUnique('users_email_normalized_unique');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'phone_normalized')) {
                $table->dropColumn('phone_normalized');
            }
            if (Schema::hasColumn('users', 'email_normalized')) {
                $table->dropColumn('email_normalized');
            }
        });
    }
};
