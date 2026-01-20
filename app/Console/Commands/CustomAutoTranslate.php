<?php

namespace App\Console\Commands;

use Devaslanphp\AutoTranslate\Commands\AutoTranslate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Stichoza\GoogleTranslate\GoogleTranslate;

class CustomAutoTranslate extends AutoTranslate
{
    protected $signature = 'custom:auto-translate';

    protected $description = 'This command will search everywhere in your code for translations and generate JSON files for you.';

    public function handle()
    {
        $locales = config('auto-translate.locales');
        $files = ['en.json', 'en_web.json', 'en_app.json']; // Add your JSON files here

        foreach ($locales as $locale) {
            foreach ($files as $file) {
                try {
                    Artisan::call('translatable:export ' . $locale);

                    // Adjust the file path to handle different file names
                    $filePath = lang_path(str_replace('en', $locale, $file));

                    if (File::exists($filePath)) {
                        $this->info('Translating ' . $locale . ' for ' . $file . ', please wait...');
                        $results = [];
                        $localeFile = File::get($filePath);
                        $localeFileContent = array_keys(json_decode($localeFile, true));
                        $translator = new GoogleTranslate($locale);
                        $translator->setSource(config('app.fallback_locale'));

                        foreach ($localeFileContent as $key) {
                            $results[$key] = $translator->translate($key);
                        }

                        File::put($filePath, json_encode($results, JSON_UNESCAPED_UNICODE));
                    }
                } catch (\Exception $e) {
                    $this->error('Error: ' . $e->getMessage());
                }
            }
        }

        return 0;
    }
}
