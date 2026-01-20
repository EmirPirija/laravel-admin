<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Stichoza\GoogleTranslate\GoogleTranslate;

class CustomTranslateMissing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'custom:translate-missing {type} {locale}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translate missing keys in a specific JSON file based on the provided type and locale.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $type = $this->argument('type');
        $locale = $this->argument('locale');


        $base = config('auto-translate.base_locale', 'en');


        $fileName = match ($type) {
            'web' => 'en_web.json',
            'panel' => 'en.json',
            'app' => 'en_app.json',
            default => $this->error('Invalid type specified.') && exit(Command::FAILURE),
        };


        $baseFilePath = lang_path($fileName);
        $localeFilePath = match ($type) {
            'web' => lang_path($locale . '_web.json'),
            'panel' => lang_path($locale . '.json'),
            'app' => lang_path($locale . '_app.json'),
            default => $this->error('Invalid type specified.') && exit(Command::FAILURE),
        };


        if (!File::exists($baseFilePath)) {
            $this->error("Base file '{$baseFilePath}' not found.");
            return Command::FAILURE;
        }


        $baseTranslations = json_decode(File::get($baseFilePath), true);
        $localeTranslations = File::exists($localeFilePath) ? json_decode(File::get($localeFilePath), true) : [];


        $translator = new GoogleTranslate();
        $translator->setSource($base);
        $translator->setTarget($locale);
            $newLocaleTranslations = [];
            foreach ($baseTranslations as $key => $baseTranslation) {
                try {
                        $translatedText = $translator->translate($baseTranslation);
                        $newLocaleTranslations[$key] = $translatedText;
                } catch (\Exception $e) {
                    $this->error('Error: ' . $e->getMessage());
                    $newLocaleTranslations[$key] = $baseTranslation;
                }
            }



        File::put($localeFilePath, json_encode($newLocaleTranslations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("Translation for type '{$type}' and locale '{$locale}' completed successfully.");
        return Command::SUCCESS;
    }

}
