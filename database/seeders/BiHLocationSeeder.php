<?php
 
namespace Database\Seeders;
 
use Illuminate\Database\Seeder;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
 
class BiHLocationSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Dodajem Bosnu i Hercegovinu...');
 
        // 1. DODAJ DRŽAVU - BiH (koristi postojeće kolone!)
        $bih = Country::updateOrCreate(
            ['iso2' => 'BA'],
            [
                'name' => 'Bosnia and Herzegovina',
                'iso3' => 'BIH',
                'numeric_code' => '070',
                'iso2' => 'BA',
                'phonecode' => '387',
                'capital' => 'Sarajevo',
                'currency' => 'BAM',
                'currency_name' => 'Bosnia and Herzegovina convertible mark',
                'currency_symbol' => 'KM',
                'tld' => '.ba',
                'native' => 'Bosna i Hercegovina',
                'region' => 'Europe',
                'subregion' => 'Southern Europe',
                'nationality' => 'Bosnian',
                'latitude' => 43.9159,
                'longitude' => 17.6791,
                'emoji' => '🇧🇦',
                'emojiU' => 'U+1F1E7 U+1F1E6',
            ]
        );
 
        $this->command->info("BiH Country ID: {$bih->id}");
 
        // 2. DODAJ STATES (Kantoni za FBiH, Regije za RS, BD)
        $states = $this->getStatesData($bih->id);
        
        foreach ($states as $stateData) {
            $state = State::updateOrCreate(
                ['name' => $stateData['name'], 'country_id' => $bih->id],
                $stateData
            );
            
            $this->command->info("Dodajem: {$state->name}");
            
            // 3. DODAJ CITIES za ovaj state
            if (isset($this->citiesData[$stateData['state_code']])) {
                foreach ($this->citiesData[$stateData['state_code']] as $cityData) {
                    City::updateOrCreate(
                        ['name' => $cityData['name'], 'state_id' => $state->id],
                        array_merge($cityData, [
                            'state_id' => $state->id,
                            'state_code' => $state->state_code,
                            'country_id' => $bih->id,
                            'country_code' => 'BA',
                        ])
                    );
                }
            }
        }
 
        $this->command->info('');
        $this->command->info('✅ BiH lokacije uspješno dodane!');
        $this->command->info('Ukupno states: ' . State::where('country_id', $bih->id)->count());
        $this->command->info('Ukupno cities: ' . City::where('country_id', $bih->id)->count());
    }
 
    private function getStatesData($countryId): array
    {
        return [
            // FEDERACIJA BIH - KANTONI
            ['name' => 'Unsko-sanski kanton', 'country_id' => $countryId, 'state_code' => 'USK', 'type' => 'kanton', 'latitude' => 44.8169, 'longitude' => 15.8708],
            ['name' => 'Posavski kanton', 'country_id' => $countryId, 'state_code' => 'PK', 'type' => 'kanton', 'latitude' => 45.0333, 'longitude' => 18.5000],
            ['name' => 'Tuzlanski kanton', 'country_id' => $countryId, 'state_code' => 'TK', 'type' => 'kanton', 'latitude' => 44.5381, 'longitude' => 18.6761],
            ['name' => 'Zeničko-dobojski kanton', 'country_id' => $countryId, 'state_code' => 'ZDK', 'type' => 'kanton', 'latitude' => 44.2017, 'longitude' => 17.9078],
            ['name' => 'Bosansko-podrinjski kanton Goražde', 'country_id' => $countryId, 'state_code' => 'BPK', 'type' => 'kanton', 'latitude' => 43.6667, 'longitude' => 18.9833],
            ['name' => 'Srednjobosanski kanton', 'country_id' => $countryId, 'state_code' => 'SBK', 'type' => 'kanton', 'latitude' => 44.2264, 'longitude' => 17.6656],
            ['name' => 'Hercegovačko-neretvanski kanton', 'country_id' => $countryId, 'state_code' => 'HNK', 'type' => 'kanton', 'latitude' => 43.3438, 'longitude' => 17.8078],
            ['name' => 'Zapadnohercegovački kanton', 'country_id' => $countryId, 'state_code' => 'ZHK', 'type' => 'kanton', 'latitude' => 43.3833, 'longitude' => 17.6000],
            ['name' => 'Kanton Sarajevo', 'country_id' => $countryId, 'state_code' => 'KS', 'type' => 'kanton', 'latitude' => 43.8564, 'longitude' => 18.4131],
            ['name' => 'Kanton 10 (Livanjski)', 'country_id' => $countryId, 'state_code' => 'K10', 'type' => 'kanton', 'latitude' => 43.8269, 'longitude' => 17.0075],
            
            // REPUBLIKA SRPSKA - REGIJE
            ['name' => 'Banjalučka regija', 'country_id' => $countryId, 'state_code' => 'RSBL', 'type' => 'regija', 'latitude' => 44.7722, 'longitude' => 17.1910],
            ['name' => 'Dobojska regija', 'country_id' => $countryId, 'state_code' => 'RSDO', 'type' => 'regija', 'latitude' => 44.7333, 'longitude' => 18.0833],
            ['name' => 'Bijeljinska regija', 'country_id' => $countryId, 'state_code' => 'RSBI', 'type' => 'regija', 'latitude' => 44.7564, 'longitude' => 19.2142],
            ['name' => 'Zvorničko-birčanska regija', 'country_id' => $countryId, 'state_code' => 'RSZV', 'type' => 'regija', 'latitude' => 44.3833, 'longitude' => 19.1000],
            ['name' => 'Sarajevsko-romanijska regija', 'country_id' => $countryId, 'state_code' => 'RSSR', 'type' => 'regija', 'latitude' => 43.8167, 'longitude' => 18.5333],
            ['name' => 'Fočanska regija', 'country_id' => $countryId, 'state_code' => 'RSFO', 'type' => 'regija', 'latitude' => 43.5000, 'longitude' => 18.7833],
            ['name' => 'Trebinjska regija', 'country_id' => $countryId, 'state_code' => 'RSTR', 'type' => 'regija', 'latitude' => 42.7117, 'longitude' => 18.3444],
            ['name' => 'Prijedorska regija', 'country_id' => $countryId, 'state_code' => 'RSPR', 'type' => 'regija', 'latitude' => 44.9833, 'longitude' => 16.7167],
            
            // BRČKO DISTRIKT
            ['name' => 'Brčko Distrikt', 'country_id' => $countryId, 'state_code' => 'BD', 'type' => 'distrikt', 'latitude' => 44.8725, 'longitude' => 18.8106],
        ];
    }
 
    private array $citiesData = [
        // USK - Unsko-sanski kanton
        'USK' => [
            ['name' => 'Bihać', 'latitude' => 44.8169, 'longitude' => 15.8708],
            ['name' => 'Cazin', 'latitude' => 44.9667, 'longitude' => 15.9428],
            ['name' => 'Velika Kladuša', 'latitude' => 45.1833, 'longitude' => 15.8056],
            ['name' => 'Bosanska Krupa', 'latitude' => 44.8833, 'longitude' => 16.1500],
            ['name' => 'Bosanski Petrovac', 'latitude' => 44.5536, 'longitude' => 16.3697],
            ['name' => 'Buzim', 'latitude' => 45.0500, 'longitude' => 16.0333],
            ['name' => 'Ključ', 'latitude' => 44.5333, 'longitude' => 16.7833],
            ['name' => 'Sanski Most', 'latitude' => 44.7667, 'longitude' => 16.6667],
        ],
 
        // PK - Posavski kanton
        'PK' => [
            ['name' => 'Orašje', 'latitude' => 45.0333, 'longitude' => 18.6833],
            ['name' => 'Odžak', 'latitude' => 45.0167, 'longitude' => 18.3167],
            ['name' => 'Domaljevac-Šamac', 'latitude' => 45.0500, 'longitude' => 18.5167],
        ],
 
        // TK - Tuzlanski kanton
        'TK' => [
            ['name' => 'Tuzla', 'latitude' => 44.5381, 'longitude' => 18.6761],
            ['name' => 'Lukavac', 'latitude' => 44.5417, 'longitude' => 18.5250],
            ['name' => 'Gračanica', 'latitude' => 44.7000, 'longitude' => 18.3000],
            ['name' => 'Gradačac', 'latitude' => 44.8833, 'longitude' => 18.4333],
            ['name' => 'Srebrenik', 'latitude' => 44.7083, 'longitude' => 18.4917],
            ['name' => 'Banovići', 'latitude' => 44.4078, 'longitude' => 18.5319],
            ['name' => 'Živinice', 'latitude' => 44.4500, 'longitude' => 18.6500],
            ['name' => 'Kalesija', 'latitude' => 44.4333, 'longitude' => 18.8500],
            ['name' => 'Kladanj', 'latitude' => 44.2250, 'longitude' => 18.6917],
            ['name' => 'Sapna', 'latitude' => 44.5000, 'longitude' => 19.0000],
            ['name' => 'Teočak', 'latitude' => 44.5833, 'longitude' => 18.9833],
            ['name' => 'Čelić', 'latitude' => 44.7167, 'longitude' => 18.8167],
            ['name' => 'Doboj Istok', 'latitude' => 44.7333, 'longitude' => 18.0833],
        ],
 
        // ZDK - Zeničko-dobojski kanton
        'ZDK' => [
            ['name' => 'Zenica', 'latitude' => 44.2017, 'longitude' => 17.9078],
            ['name' => 'Visoko', 'latitude' => 43.9889, 'longitude' => 18.1778],
            ['name' => 'Kakanj', 'latitude' => 44.1333, 'longitude' => 18.1167],
            ['name' => 'Maglaj', 'latitude' => 44.5500, 'longitude' => 18.1000],
            ['name' => 'Zavidovići', 'latitude' => 44.4500, 'longitude' => 18.1500],
            ['name' => 'Žepče', 'latitude' => 44.4333, 'longitude' => 18.0333],
            ['name' => 'Tešanj', 'latitude' => 44.6111, 'longitude' => 17.9833],
            ['name' => 'Doboj Jug', 'latitude' => 44.7167, 'longitude' => 18.0500],
            ['name' => 'Usora', 'latitude' => 44.5667, 'longitude' => 17.9333],
            ['name' => 'Olovo', 'latitude' => 44.1333, 'longitude' => 18.5833],
            ['name' => 'Vareš', 'latitude' => 44.1667, 'longitude' => 18.3333],
            ['name' => 'Breza', 'latitude' => 44.0167, 'longitude' => 18.2667],
        ],
 
        // BPK - Bosansko-podrinjski kanton Goražde
        'BPK' => [
            ['name' => 'Goražde', 'latitude' => 43.6667, 'longitude' => 18.9833],
            ['name' => 'Pale-Prača', 'latitude' => 43.7500, 'longitude' => 18.8833],
            ['name' => 'Foča (FBiH)', 'latitude' => 43.5500, 'longitude' => 18.7500],
        ],
 
        // SBK - Srednjobosanski kanton
        'SBK' => [
            ['name' => 'Travnik', 'latitude' => 44.2264, 'longitude' => 17.6656],
            ['name' => 'Vitez', 'latitude' => 44.1500, 'longitude' => 17.7833],
            ['name' => 'Novi Travnik', 'latitude' => 44.1667, 'longitude' => 17.6500],
            ['name' => 'Bugojno', 'latitude' => 44.0500, 'longitude' => 17.4500],
            ['name' => 'Gornji Vakuf-Uskoplje', 'latitude' => 43.9333, 'longitude' => 17.5833],
            ['name' => 'Fojnica', 'latitude' => 43.9667, 'longitude' => 17.9000],
            ['name' => 'Busovača', 'latitude' => 44.1000, 'longitude' => 17.8833],
            ['name' => 'Jajce', 'latitude' => 44.3333, 'longitude' => 17.2667],
            ['name' => 'Donji Vakuf', 'latitude' => 44.1500, 'longitude' => 17.3833],
            ['name' => 'Kreševo', 'latitude' => 43.8833, 'longitude' => 18.0500],
            ['name' => 'Kiseljak', 'latitude' => 43.9500, 'longitude' => 18.0833],
            ['name' => 'Dobretići', 'latitude' => 44.4000, 'longitude' => 17.1667],
        ],
 
        // HNK - Hercegovačko-neretvanski kanton
        'HNK' => [
            ['name' => 'Mostar', 'latitude' => 43.3438, 'longitude' => 17.8078],
            ['name' => 'Konjic', 'latitude' => 43.6500, 'longitude' => 17.9667],
            ['name' => 'Jablanica', 'latitude' => 43.6667, 'longitude' => 17.7500],
            ['name' => 'Prozor-Rama', 'latitude' => 43.8167, 'longitude' => 17.6167],
            ['name' => 'Čapljina', 'latitude' => 43.1167, 'longitude' => 17.6833],
            ['name' => 'Čitluk', 'latitude' => 43.2333, 'longitude' => 17.7000],
            ['name' => 'Neum', 'latitude' => 42.9167, 'longitude' => 17.6167],
            ['name' => 'Stolac', 'latitude' => 43.0833, 'longitude' => 17.9667],
            ['name' => 'Ravno', 'latitude' => 42.9000, 'longitude' => 17.9500],
        ],
 
        // ZHK - Zapadnohercegovački kanton
        'ZHK' => [
            ['name' => 'Široki Brijeg', 'latitude' => 43.3833, 'longitude' => 17.6000],
            ['name' => 'Ljubuški', 'latitude' => 43.1833, 'longitude' => 17.5333],
            ['name' => 'Posušje', 'latitude' => 43.4667, 'longitude' => 17.3333],
            ['name' => 'Grude', 'latitude' => 43.3667, 'longitude' => 17.4167],
        ],
 
        // KS - Kanton Sarajevo
        'KS' => [
            ['name' => 'Sarajevo - Centar', 'latitude' => 43.8564, 'longitude' => 18.4131],
            ['name' => 'Sarajevo - Stari Grad', 'latitude' => 43.8589, 'longitude' => 18.4319],
            ['name' => 'Sarajevo - Novo Sarajevo', 'latitude' => 43.8500, 'longitude' => 18.3833],
            ['name' => 'Sarajevo - Novi Grad', 'latitude' => 43.8333, 'longitude' => 18.3500],
            ['name' => 'Ilidža', 'latitude' => 43.8297, 'longitude' => 18.3103],
            ['name' => 'Vogošća', 'latitude' => 43.9000, 'longitude' => 18.3500],
            ['name' => 'Hadžići', 'latitude' => 43.8167, 'longitude' => 18.2000],
            ['name' => 'Ilijaš', 'latitude' => 43.9500, 'longitude' => 18.2667],
            ['name' => 'Trnovo (FBiH)', 'latitude' => 43.6667, 'longitude' => 18.4500],
        ],
 
        // K10 - Kanton 10 (Livanjski)
        'K10' => [
            ['name' => 'Livno', 'latitude' => 43.8269, 'longitude' => 17.0075],
            ['name' => 'Tomislavgrad', 'latitude' => 43.7167, 'longitude' => 17.2333],
            ['name' => 'Kupres', 'latitude' => 43.9833, 'longitude' => 17.2833],
            ['name' => 'Glamoč', 'latitude' => 44.0500, 'longitude' => 16.8500],
            ['name' => 'Bosansko Grahovo', 'latitude' => 44.1833, 'longitude' => 16.6500],
            ['name' => 'Drvar', 'latitude' => 44.3667, 'longitude' => 16.3833],
        ],
 
        // RS - Banjalučka regija
        'RSBL' => [
            ['name' => 'Banja Luka', 'latitude' => 44.7722, 'longitude' => 17.1910],
            ['name' => 'Laktaši', 'latitude' => 44.9000, 'longitude' => 17.3000],
            ['name' => 'Gradiška', 'latitude' => 45.1333, 'longitude' => 17.2500],
            ['name' => 'Srbac', 'latitude' => 45.1000, 'longitude' => 17.5167],
            ['name' => 'Čelinac', 'latitude' => 44.7333, 'longitude' => 17.3167],
            ['name' => 'Kotor Varoš', 'latitude' => 44.6167, 'longitude' => 17.3667],
            ['name' => 'Kneževo', 'latitude' => 44.4833, 'longitude' => 17.3833],
            ['name' => 'Mrkonjić Grad', 'latitude' => 44.4167, 'longitude' => 17.0833],
            ['name' => 'Šipovo', 'latitude' => 44.2833, 'longitude' => 17.0833],
            ['name' => 'Jezero', 'latitude' => 44.3500, 'longitude' => 17.1500],
            ['name' => 'Ribnik', 'latitude' => 44.4667, 'longitude' => 16.8333],
            ['name' => 'Petrovac', 'latitude' => 44.6167, 'longitude' => 16.3667],
        ],
 
        // RS - Dobojska regija
        'RSDO' => [
            ['name' => 'Doboj', 'latitude' => 44.7333, 'longitude' => 18.0833],
            ['name' => 'Teslić', 'latitude' => 44.6000, 'longitude' => 17.8500],
            ['name' => 'Modriča', 'latitude' => 44.9500, 'longitude' => 18.3000],
            ['name' => 'Derventa', 'latitude' => 44.9833, 'longitude' => 17.9000],
            ['name' => 'Brod', 'latitude' => 45.1333, 'longitude' => 18.0167],
            ['name' => 'Šamac', 'latitude' => 45.0667, 'longitude' => 18.4667],
            ['name' => 'Vukosavlje', 'latitude' => 44.9667, 'longitude' => 18.2667],
            ['name' => 'Pelagićevo', 'latitude' => 44.9000, 'longitude' => 18.5500],
            ['name' => 'Petrovo', 'latitude' => 44.6333, 'longitude' => 18.3333],
            ['name' => 'Stanari', 'latitude' => 44.7667, 'longitude' => 17.8333],
        ],
 
        // RS - Bijeljinska regija
        'RSBI' => [
            ['name' => 'Bijeljina', 'latitude' => 44.7564, 'longitude' => 19.2142],
            ['name' => 'Ugljevik', 'latitude' => 44.6833, 'longitude' => 19.0000],
            ['name' => 'Lopare', 'latitude' => 44.6333, 'longitude' => 18.8333],
        ],
 
        // RS - Zvorničko-birčanska regija
        'RSZV' => [
            ['name' => 'Zvornik', 'latitude' => 44.3833, 'longitude' => 19.1000],
            ['name' => 'Bratunac', 'latitude' => 44.1833, 'longitude' => 19.3333],
            ['name' => 'Srebrenica', 'latitude' => 44.1000, 'longitude' => 19.3000],
            ['name' => 'Milići', 'latitude' => 44.1667, 'longitude' => 19.0833],
            ['name' => 'Vlasenica', 'latitude' => 44.1833, 'longitude' => 18.9333],
            ['name' => 'Šekovići', 'latitude' => 44.3000, 'longitude' => 18.8667],
            ['name' => 'Osmaci', 'latitude' => 44.3500, 'longitude' => 18.9667],
        ],
 
        // RS - Sarajevsko-romanijska regija
        'RSSR' => [
            ['name' => 'Istočno Sarajevo', 'latitude' => 43.8167, 'longitude' => 18.5333],
            ['name' => 'Pale', 'latitude' => 43.8167, 'longitude' => 18.5667],
            ['name' => 'Sokolac', 'latitude' => 43.9500, 'longitude' => 18.8000],
            ['name' => 'Han Pijesak', 'latitude' => 44.0667, 'longitude' => 18.9500],
            ['name' => 'Rogatica', 'latitude' => 43.8000, 'longitude' => 19.0000],
            ['name' => 'Višegrad', 'latitude' => 43.7833, 'longitude' => 19.2833],
            ['name' => 'Rudo', 'latitude' => 43.6167, 'longitude' => 19.3667],
            ['name' => 'Istočna Ilidža', 'latitude' => 43.8333, 'longitude' => 18.4333],
            ['name' => 'Istočni Stari Grad', 'latitude' => 43.7833, 'longitude' => 18.4667],
            ['name' => 'Istočno Novo Sarajevo', 'latitude' => 43.8333, 'longitude' => 18.4167],
            ['name' => 'Trnovo (RS)', 'latitude' => 43.6667, 'longitude' => 18.4333],
        ],
 
        // RS - Fočanska regija
        'RSFO' => [
            ['name' => 'Foča', 'latitude' => 43.5000, 'longitude' => 18.7833],
            ['name' => 'Kalinovik', 'latitude' => 43.5000, 'longitude' => 18.4500],
            ['name' => 'Čajniče', 'latitude' => 43.5500, 'longitude' => 19.0667],
            ['name' => 'Ustiprača', 'latitude' => 43.6333, 'longitude' => 19.1167],
        ],
 
        // RS - Trebinjska regija
        'RSTR' => [
            ['name' => 'Trebinje', 'latitude' => 42.7117, 'longitude' => 18.3444],
            ['name' => 'Bileća', 'latitude' => 42.8667, 'longitude' => 18.4333],
            ['name' => 'Gacko', 'latitude' => 43.1667, 'longitude' => 18.5333],
            ['name' => 'Nevesinje', 'latitude' => 43.2500, 'longitude' => 18.1167],
            ['name' => 'Berkovići', 'latitude' => 43.1000, 'longitude' => 18.1833],
            ['name' => 'Ljubinje', 'latitude' => 42.9500, 'longitude' => 18.0833],
            ['name' => 'Istočni Mostar', 'latitude' => 43.3500, 'longitude' => 17.9000],
        ],
 
        // RS - Prijedorska regija
        'RSPR' => [
            ['name' => 'Prijedor', 'latitude' => 44.9833, 'longitude' => 16.7167],
            ['name' => 'Kozarska Dubica', 'latitude' => 45.1833, 'longitude' => 16.8000],
            ['name' => 'Kostajnica', 'latitude' => 45.2167, 'longitude' => 16.5333],
            ['name' => 'Novi Grad', 'latitude' => 45.0500, 'longitude' => 16.3833],
            ['name' => 'Krupa na Uni', 'latitude' => 44.8833, 'longitude' => 16.3167],
            ['name' => 'Oštra Luka', 'latitude' => 44.8333, 'longitude' => 16.5500],
        ],
 
        // Brčko Distrikt
        'BD' => [
            ['name' => 'Brčko', 'latitude' => 44.8725, 'longitude' => 18.8106],
        ],
    ];
}