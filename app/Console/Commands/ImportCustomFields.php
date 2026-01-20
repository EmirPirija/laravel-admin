<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CustomField;
use App\Models\CustomFieldCategory;
use Illuminate\Support\Facades\DB;
use Exception;

class ImportCustomFields extends Command
{
    /**
     * Potpis komande sa opcijom za simulaciju.
     * Primjer: php artisan custom-fields:import fajl.csv --dry-run
     */
    protected $signature = 'custom-fields:import {file} {--dry-run : Pokreni simulaciju bez upisa u bazu}';

    protected $description = 'Import custom fields from CSV with detailed error reporting and dry-run support';

    public function handle()
    {
        $file = $this->argument('file');
        $isDryRun = $this->option('dry-run');

        // Provjera da li fajl postoji
        if (!file_exists($file)) {
            $this->error("Fajl nije pronađen: {$file}");
            return 1;
        }

        if (($handle = fopen($file, 'r')) !== false) {
            $this->info("Započinjem import..." . ($isDryRun ? " [SIMULACIJA]" : ""));
            
            // Čitamo header (prvi red)
            $header = fgetcsv($handle, 4096, ",");
            
            // Provjera specijalnih kolona
            $hasPriority = in_array('priority', $header);
            $hasDependency = in_array('dependent_on', $header);
            $hasParentMapping = in_array('parent_mapping', $header);
            
            if ($hasPriority) $this->info("✓ Pronađena 'priority' kolona");
            if ($hasDependency) $this->info("✓ Pronađena 'dependent_on' kolona");
            if ($hasParentMapping) $this->info("✓ Pronađena 'parent_mapping' kolona");
            
            $count = 0;
            $skipped = 0;
            $fieldsData = []; // Spremamo podatke za drugi prolaz (zavisnosti)

            // Pokrećemo transakciju baze podataka
            DB::beginTransaction();
            
            try {
                $lineNumber = 1; // Brojač linija u CSV-u (Header je 1)

                // ---------------------------------------------------------
                // PRVI PROLAZ: Kreiranje polja i kategorija
                // ---------------------------------------------------------
                while (($row = fgetcsv($handle, 4096, ",")) !== false) {
                    $lineNumber++;

                    // Preskoči prazne redove
                    if (!$row || empty(array_filter($row)) || (count($row) === 1 && is_null($row[0]))) {
                        continue;
                    }

                    // --- DETALJNA DETEKCIJA GREŠAKA ---
                    if (count($header) !== count($row)) {
                        $this->error("\n--------------------------------------------------");
                        $this->error("❌ GREŠKA NA LINIJI BR: " . $lineNumber);
                        $this->warn("Očekivano kolona: " . count($header));
                        $this->warn("Pronađeno kolona: " . count($row));
                        $this->line("Sadržaj problematičnog reda:");
                        $this->line(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        $this->warn("Savjet: Provjeri da li negdje fali zarez ili navodnik.");
                        $this->error("--------------------------------------------------\n");
                        
                        $skipped++;
                        continue;
                    }

                    // Mapiranje podataka
                    $data = array_combine($header, $row);
                    $fieldsData[] = $data; // Sačuvaj za kasnije (dependencies)
                    
                    // Kreiraj custom field
                    $customField = CustomField::create([
                        'name'       => $data['name'],
                        'type'       => $data['type'],
                        'required'   => $data['required'] ?? 0,
                        'status'     => $data['status'] ?? 1,
                        'priority'   => $data['priority'] ?? 100,
                        'values'     => $this->formatValues($data['values']),
                        // dependent_on dodajemo u drugom prolazu
                    ]);

                    // Povezivanje sa kategorijama
                    if (!empty($data['category_ids'])) {
                        // Razdvaja ID-jeve po zarezu ili uspravnoj crti
                        $categoryIds = preg_split('/[,|]/', $data['category_ids']);
                        
                        foreach ($categoryIds as $categoryId) {
                            $catId = trim($categoryId);
                            if (empty($catId)) continue;

                            // Provjera da li kategorija postoji prije povezivanja
                            if (DB::table('categories')->where('id', $catId)->exists()) {
                                CustomFieldCategory::create([
                                    'custom_field_id' => $customField->id,
                                    'category_id'     => $catId
                                ]);
                            } else {
                                $this->warn("Kategorija ID {$catId} ne postoji u bazi. Preskačem povezivanje za polje '{$data['name']}'.");
                            }
                        }
                    }
                    
                    $count++;
                    
                    // Progress bar u tekstualnom obliku
                    if ($count % 100 === 0) {
                        $this->info("  Obrađeno {$count} polja...");
                    }
                }

                // ---------------------------------------------------------
                // DRUGI PROLAZ: Postavljanje zavisnosti (Parent/Child)
                // ---------------------------------------------------------
                $this->info("\n🔗 Provjeravam i postavljam zavisnosti između polja...");
                $dependenciesSet = 0;
                
                foreach ($fieldsData as $data) {
                    if (!empty($data['dependent_on'])) {
                        $childField = CustomField::where('name', $data['name'])->first();
                        $parentField = CustomField::where('name', $data['dependent_on'])->first();
                
                        if ($childField && $parentField) {
                            $parentMapping = isset($data['parent_mapping'])
                                ? $this->formatParentMapping($data['parent_mapping'])
                                : null;
                
                            $childField->update([
                                'dependent_on'   => $parentField->id,
                                'parent_mapping' => $parentMapping
                            ]);
                
                            $dependenciesSet++;
                        } else {
                            $this->warn("Upozorenje: Ne mogu povezati '{$data['name']}' jer roditelj '{$data['dependent_on']}' nije pronađen.");
                        }
                    }
                }

                // ---------------------------------------------------------
                // ZAVRŠETAK: Commit ili Rollback
                // ---------------------------------------------------------
                if ($isDryRun) {
                    DB::rollBack();
                    $this->warn("\n--------------------------------------------------");
                    $this->warn("⚠ [DRY RUN] Ovo je bila simulacija.");
                    $this->warn("⚠ Sve promjene su poništene (Rollback).");
                    $this->warn("ℹ Broj polja koja bi bila kreirana: {$count}");
                    $this->warn("ℹ Broj zavisnosti koje bi bile postavljene: {$dependenciesSet}");
                    
                    if ($skipped > 0) {
                        $this->error("⚠ Broj preskočenih redova zbog grešaka: {$skipped}");
                        $this->warn("  (Popravi greške u CSV-u prije pravog importa)");
                    } else {
                        $this->info("✓ Nisu pronađene greške u strukturi CSV-a.");
                    }
                    $this->warn("--------------------------------------------------");
                } else {
                    DB::commit();
                    
                    $this->info("\n========================================");
                    $this->info("✓ Uspješno uvezeno i SNIMLJENO: {$count} custom polja");
                    if ($dependenciesSet > 0) {
                        $this->info("✓ Postavljeno zavisnosti: {$dependenciesSet}");
                    }
                    if ($skipped > 0) {
                        $this->error("⚠ Preskočeno redova zbog grešaka: {$skipped}");
                    }
                    $this->info("========================================");
                }
                
                fclose($handle);
                
            } catch (Exception $e) {
                DB::rollBack();
                fclose($handle);
                $this->error("\n✗ FATALNA GREŠKA: " . $e->getMessage());
                if ($isDryRun) {
                    $this->error("Greška se desila tokom simulacije. Baza je sigurna.");
                }
                return 1;
            }
        }
        return 0;
    }

    /**
     * Formatira vrijednosti za dropdown (JSON ili Pipe separated).
     */
    private function formatValues($values)
    {
        if (empty($values)) return json_encode([]);
        
        // Ako je već validan JSON, vrati ga
        $decoded = json_decode($values, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $values;
        }

        // Inače pretvori pipe-separated string u JSON array
        $array = explode('|', $values);
        return json_encode(array_map('trim', $array), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Formatira mapiranje zavisnosti.
     */
    private function formatParentMapping($mapping)
    {
        if (empty($mapping)) return null;
        
        $decoded = json_decode($mapping, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        // Format: "key1:value1|key2:value2"
        if (strpos($mapping, ':') !== false) {
            $pairs = explode('|', $mapping);
            $result = [];
            foreach ($pairs as $pair) {
                $parts = explode(':', trim($pair), 2);
                if (count($parts) === 2) {
                    $result[trim($parts[0])] = trim($parts[1]);
                }
            }
            return json_encode($result, JSON_UNESCAPED_UNICODE);
        }

        return null;
    }
}