<?php
 
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use App\Models\Item;
use Carbon\Carbon;
 
class PublishScheduledAds extends Command
{
    protected $signature = 'ads:publish-scheduled';
    protected $description = 'Objavi sve zakazane oglase čije je vrijeme prošlo';
 
    public function handle()
    {
        $this->info('🔍 Tražim zakazane oglase...');
        
        // Prikaži sve scheduled oglase
        $allScheduled = Item::where('status', 'scheduled')->get();
        $this->info("📋 Ukupno scheduled oglasa: " . $allScheduled->count());
        
        foreach ($allScheduled as $item) {
            $this->line("   - ID: {$item->id}, Naziv: {$item->name}, Zakazano: {$item->scheduled_at}");
        }
        
        // Pronađi one koji trebaju biti objavljeni
        $itemsToPublish = Item::where('status', 'scheduled')
            ->where('scheduled_at', '<=', Carbon::now())
            ->get();
        
        $this->info("✅ Oglasa za objavu (vrijeme prošlo): " . $itemsToPublish->count());
        
        if ($itemsToPublish->count() === 0) {
            $this->warn('Nema oglasa za objavu. Trenutno vrijeme: ' . Carbon::now());
            return;
        }
        
        foreach ($itemsToPublish as $item) {
            $this->info("📢 Objavljujem: {$item->name} (ID: {$item->id})");
            
            $item->status = 'approved';
            $item->scheduled_at = null;
            $item->save();
            
            $this->info("   ✓ Objavljeno!");
        }
        
        $this->info('🎉 Gotovo!');
    }
}