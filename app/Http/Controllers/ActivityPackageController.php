<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApiResponseResources;
use App\Models\ActivityPackage;
// use App\Models\ImagePackage;
use App\Services\Availability\ActivityAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ActivityPackageController extends Controller
{
    protected $activityAvailabilityService;

    //Dependency Injection (Constructor) untuk memastikan bahwa service class diinisialisasi dan siap digunakan 
    //melalui $this->activityAvailabilityService.
    public function __construct(ActivityAvailabilityService $activityAvailabilityService)
    {
        $this->activityAvailabilityService = $activityAvailabilityService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $packages = ActivityPackage::all();
        // $packages = ActivityPackage::where('is_available', true)->paginate(2);
        // Tambahkan 'images' agar muncul di listing
        // $packages->load('images');
        return new ApiResponseResources(true, 'Activity packages retrieved successfully', $packages);
    }

    public function adminIndex()
    {
        // $packages = RentalPackage::where('is_available', true)->paginate(2);
        // $packages->load('images');
        $packages = ActivityPackage::all();
        return new ApiResponseResources(true, 'Rental packages retrieved successfully', $packages);
    }

     /**
     * Check availability for specific activity
     */
    public function checkAvailability(Request $request, $id)
    {
        $request->validate([
            'date' => 'required|date',
            'participants' => 'required|integer|min:1'
        ]);

        $isAvailable = $this->activityAvailabilityService->checkAvailability(
            $id,
            $request->date,
            $request->participants
        );

        return new ApiResponseResources(true, 'Availability checked successfully', [
            'available' => $isAvailable,
            'activity_id' => $id,
            'date' => $request->date,
            'participants' => $request->participants
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
     public function store(Request $request)
    {
        $messages =[
            'name.required' => 'Name is required',
            'name.max' => 'Name may not be greater than 255 characters!',
            'description.required' => 'Fill the description here',
            'itinerary.required' => 'Itinerary is required',
            'includes.required' => 'Includes is required',
            'excludes.required'=> 'Excludes is required',
            'price_per_person.required' => 'Price is required',
            'price_per_person.numeric' => 'The price must be entered as a number',
            'min_persons.required' => 'Minimum number of people is required',
            'min_persons.numeric' => 'Minimum number of people must be entered as a number.',
            'duration_hours.required' => 'Duration hours is required',
            'duration_hours.numeric' => 'Duration hours must be entered as a number.',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image'=> 'The uploaded file must be an image (jpeg, png, jpg, gif, or svg).',
            'image.*.mimes'=> 'The image file format is invalid. Only :values formats are allowed.',
            'image.*.max' => 'The image file size may not exceed :max kilobytes.',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'description' => 'required',
            'itinerary' => 'required',
            'includes' => 'required',
            'excludes' => 'required',
            'price_per_person' => 'required|numeric',
            'min_persons' => 'required|numeric',
            'duration_hours' => 'required|numeric',
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $activityPackage = ActivityPackage::create([
            'name' => $request->name,
            'description' => $request->description,
            'itinerary' => $request->itinerary,
            'includes' => $request->includes,
            'excludes' => $request->excludes,
            'price_per_person' => $request->price_per_person,
            'min_persons' => $request->min_persons,
            'duration_hours' => $request->duration_hours,
            'is_available' => $request->is_available ?? true
        ]);
        
        // Simpan Gambar Menggunakan Relasi Polimorfik
        if ($request->hasFile("image")) {
            foreach ($request->file('image') as $imageFile) {
                // Simpan file ke disk'
                $path = $imageFile->store('packages/activity', 'public'); 

                // MENGGUNAKAN RELASI POLYMORPHIC:
                // Larik $activityPackage->images()->create() akan secara otomatis
                // mengisi imageable_id dengan $activityPackage->id 
                // dan imageable_type dengan 'App\Models\ActivityPackage'
                $activityPackage->images()->create([
                    'image' => $path,
                ]);
            }
        }

        if (!$activityPackage) {
            return new ApiResponseResources(false, 'Failed to save Activity Package!', null, 500);
        }

        return new ApiResponseResources(true, 'Activity Package Saved Successfully!', $activityPackage->load('images'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $activityPackage = ActivityPackage::find($id);

        if (!$activityPackage) {
            return new ApiResponseResources(false, 'Activity Package Not Found!', null, 404);
        }

        //// menambahkan .load('images') jika ` ingin gambar muncul di respons GET tunggal
        return new ApiResponseResources(true, 'Actvity Package Found!', $activityPackage->load('images'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $activityPackage = ActivityPackage::find($id);

        if (!$activityPackage) {
            return new ApiResponseResources(false, 'Activity Package Not Found!', null, 404);
        }

        $messages =[
            'name.required' => 'Name is required',
            'name.max' => 'Name may not be greater than 255 characters!',
            'description.required' => 'Fill the description here',
            'itinerary.required' => 'Itinerary is required',
            'includes.required' => 'Includes is required',
            'excludes.required'=> 'Excludes is required',
            'price_per_person.required' => 'Price is required',
            'price_per_person.numeric' => 'The price must be entered as a number',
            'min_persons.required' => 'Minimum number of people is required',
            'min_persons.numeric' => 'Minimum number of people must be entered as a number.',
            'duration_hours.required' => 'Duration hours is required',
            'duration_hours.numeric' => 'Duration hours must be entered as a number.',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image'=> 'The uploaded file must be an image (jpeg, png, jpg, gif, or svg).',
            'image.*.mimes'=> 'The image file format is invalid. Only :values formats are allowed.',
            'image.*.max' => 'The image file size may not exceed :max kilobytes.',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'description' => 'required',
            'itinerary' => 'required',
            'includes' => 'required',
            'excludes' => 'required',
            'price_per_person' => 'required|numeric',
            'min_persons' => 'required|numeric',
            'duration_hours' => 'required|numeric',
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $activityPackage->update([
            'name' => $request->name,
            'description' => $request->description,
            'itinerary' => $request->itinerary,
            'includes' => $request->includes,
            'excludes' => $request->excludes,
            'price_per_person' => $request->price_per_person,
            'min_persons' => $request->min_persons,
            'duration_hours' => $request->duration_hours,
            'is_available' => $request->is_available ?? true
        ]);

        //Perbarui Gambar Menggunakan Relasi Polimorfik
        if ($request->hasFile("image")) {
            // Hapus gambar lama (dari storage dan database)
            foreach ($activityPackage->images as $oldImage) {
                Storage::disk('public')->delete($oldImage->image);
                $oldImage->delete(); // Hapus dari tabel image_packages
            }

            // Simpan gambar baru
            foreach ($request->file('image') as $imageFile) {
                $path = $imageFile->store('packages/activity', 'public');

                // MENGGUNAKAN RELASI POLYMORPHIC
                $activityPackage->images()->create([
                    'image' => $path,
                ]);
            }
        }

        return new ApiResponseResources(true, 'Activity Package Successfully Updated!', $activityPackage->load('images'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(string $id)
    {
        $activityPackage = ActivityPackage::find($id);

        if (!$activityPackage) {
            return new ApiResponseResources(false, 'Activity Package Not Found!', null, 404);
        }

        // Hapus gambar dari storage dan database menggunakan relasi
        foreach ($activityPackage->images as $image) {
            Storage::disk('public')->delete($image->image);
            $image->delete();
        }

        $activityPackage->delete();

        return new ApiResponseResources(true, 'Activity Package Successfully Deleted!', Null, 200);
    }
}
