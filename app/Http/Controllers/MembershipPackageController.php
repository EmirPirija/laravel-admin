<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MembershipPackageController extends Controller
{
    public function index()
    {
        $languages = Language::all();
        $currency_symbol = system_setting('currency_symbol');
        
        return view('package.membership', compact('languages', 'currency_symbol'));
    }

    public function show(Request $request)
    {
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');

        $sql = Package::where('package_type', 'membership')
            ->with('package_translations');

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();

        foreach ($result as $row) {
            $tempRow = array();
            $tempRow['id'] = $row->id;
            $tempRow['name'] = $row->name;
            $tempRow['membership_tier'] = strtoupper($row->membership_tier ?? 'N/A');
            $tempRow['icon'] = $row->icon;
            $tempRow['price'] = $row->price;
            $tempRow['discount_in_percentage'] = $row->discount_in_percentage . '%';
            $tempRow['final_price'] = $row->final_price;
            $tempRow['duration'] = $row->duration ? $row->duration . ' days' : 'Unlimited';
            $tempRow['description'] = $row->description;
            $tempRow['ios_product_id'] = $row->ios_product_id;
            $tempRow['status'] = $row->status;
            
            $tempRow['operate'] = '';
            
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name.1' => 'required',
            'description.1' => 'required',
            'price' => 'required|numeric',
            'final_price' => 'required|numeric',
            'membership_tier' => 'required|in:pro,shop',
            'duration' => 'required|integer|min:1',
            'icon' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        DB::beginTransaction();
        try {
            // Upload icon
            $iconPath = null;
            if ($request->hasFile('icon')) {
                $icon = $request->file('icon');
                $iconPath = $icon->store('membership_icons', 'public');
            }

            // Parse features from textarea
            $features = [];
            if ($request->features) {
                $features = array_filter(explode("\n", $request->features));
            }

            // Create package
            $package = Package::create([
                'package_type' => 'membership',
                'membership_tier' => $request->membership_tier,
                'price' => $request->price,
                'discount_in_percentage' => $request->discount_in_percentage ?? 0,
                'final_price' => $request->final_price,
                'icon' => $iconPath ? asset('storage/' . $iconPath) : null,
                'duration' => $request->duration,
                'ios_product_id' => $request->ios_product_id,
                'features' => json_encode($features),
                'status' => 1,
            ]);

            // Save translations
            foreach ($request->languages as $lang_id) {
                DB::table('package_translations')->insert([
                    'package_id' => $package->id,
                    'language_id' => $lang_id,
                    'name' => $request->name[$lang_id],
                    'description' => $request->description[$lang_id],
                ]);
            }

            DB::commit();
            return response()->json(['error' => false, 'message' => 'Membership package created successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => true, 'message' => $e->getMessage()]);
        }
    }

    public function update(Request $request, $id)
    {
        // Similar to store, but with update logic
        // Implement based on your existing package update pattern
    }

    public function destroy($id)
    {
        Package::find($id)->delete();
        return response()->json(['error' => false, 'message' => 'Deleted successfully']);
    }
}
