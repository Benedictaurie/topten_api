<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\ApiResponseResources;
use App\Models\TourPackage;
// use App\Models\ImagePackage;
use App\Services\Availability\TourAvailabilityService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;


class TourPackageController extends Controller
{
    protected $tourAvailabilityService;

    // 1. Dependency Injection di Constructor
    public function __construct(TourAvailabilityService $tourAvailabilityService)
    {
        $this->tourAvailabilityService = $tourAvailabilityService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $packages = TourPackage::where('is_available', true)->paginate(2);
        // Tambahkan 'images' agar muncul di listing
        $packages->load('images'); 
        return new ApiResponseResources(true, 'Tour packages retrieved successfully', $packages);
    }

    /**
     * Check availability for specific tour
     */
    public function checkAvailability(Request $request, $id)
    {
        // Validasi: Perlu tanggal mulai dan jumlah peserta
        $request->validate(rules: [
            'start_date' => 'required|date',
            'participants' => 'required|integer|min:1'
        ]);

        // Panggil Service Class
        $isAvailable = $this->tourAvailabilityService->checkAvailability(
            $id,
            $request->start_date,
            $request->participants
        );

        return new ApiResponseResources(true, 'Availability checked successfully', [
            'available' => $isAvailable,
            'tour_id' => $id,
            'start_date' => $request->start_date,
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
            'price_per_person.required' => 'Price per person is required',
            'price_per_person.numeric' => 'The price must be entered as a number',
            'min_persons.required' => 'Minimum number of people is required',
            'min_persons.numeric' => 'Minimum number of people must be entered as a number.',
            'duration_days.required' => 'Duration hours is required',
            'duration_days.numeric' => 'Duration hours must be entered as a number.',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image'=> 'The uploaded file must be an image (jpeg, png, jpg, gif, or svg).',
            'image.*.mimes'=> 'The image file format is invalid. Only :values formats are allowed.',
            'image.*.max' => 'The image file size may not exceed :max kilobytes.',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'description' => 'required',
            'price_per_person' => 'required|numeric',
            'min_persons' => 'required|numeric',
            'duration_days' => 'required|numeric', 
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $tourPackage = TourPackage::create([
            'name' => $request->name,
            'description' => $request->description,
            'price_per_person' => $request->price_per_person,
            'min_persons' => $request->min_persons,
            'duration_days' => $request->duration_days, 
            'is_available' => $request->is_available ?? true
        ]);

        // Simpan Gambar Menggunakan Relasi Polimorfik (sama seperti Activity)
        if ($request->hasFile("image")) {
            foreach ($request->file('image') as $imageFile) {
                $path = $imageFile->store('packages/tour', 'public'); // Folder 'tour'
                $tourPackage->images()->create([
                    'image' => $path,
                ]);
            }
        }

        if (!$tourPackage) {
            return new ApiResponseResources(false, 'Failed to save Tour Package!', null, 500);
        }

        return new ApiResponseResources(true, 'Tour Package Saved Successfully!', $tourPackage->load('images'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $tourPackage = TourPackage::find($id);

        if (!$tourPackage) {
            return new ApiResponseResources(false, 'Tour Package Not Found!', null, 404);
        }
        return new ApiResponseResources(true, 'Tour Package Found!', $tourPackage->load('images'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $tourPackage = TourPackage::find($id);

        if (!$tourPackage) {
            return new ApiResponseResources(false, 'Tour Package Not Found!', null, 404);
        }

        $messages =[
            'name.required' => 'Name is required',
            'name.max' => 'Name may not be greater than 255 characters!',
            'description.required' => 'Fill the description here',
            'price_per_person.required' => 'Price is required',
            'price_per_person.numeric' => 'The price must be entered as a number',
            'min_persons.required' => 'Minimum number of people is required',
            'min_persons.numeric' => 'Minimum number of people must be entered as a number.',
            'duration_days.required' => 'Duration hours is required',
            'duration_days.numeric' => 'Duration hours must be entered as a number.',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image'=> 'The uploaded file must be an image (jpeg, png, jpg, gif, or svg).',
            'image.*.mimes'=> 'The image file format is invalid. Only :values formats are allowed.',
            'image.*.max' => 'The image file size may not exceed :max kilobytes.',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'description' => 'required',
            'price_per_person' => 'required|numeric',
            'min_persons' => 'required|numeric',
            'duration_days' => 'required|numeric', 
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $tourPackage->update([
            'name' => $request->name,
            'description' => $request->description,
            'price_per_person' => $request->price_per_person,
            'min_persons' => $request->min_persons,
            'duration_days' => $request->duration_days,
            'is_available' => $request->is_available ?? true
        ]);

        // Logika Gambar 
        if ($request->hasFile("image")) {
            foreach ($tourPackage->images as $oldImage) {
                Storage::disk('public')->delete($oldImage->image);
                $oldImage->delete();
            }
            foreach ($request->file('image') as $imageFile) {
                $path = $imageFile->store('packages/tour', 'public'); 
                $tourPackage->images()->create([
                    'image' => $path
                ]);
            }
        }

        return new ApiResponseResources(true, 'Tour Package Successfully Updated!', $tourPackage->load('images'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(string $id)
    {
        $tourPackage = TourPackage::find($id);

        if (!$tourPackage) {
            return new ApiResponseResources(false, 'Tour Package Not Found!', null, 404);
        }

        // Hapus gambar dari storage dan database
        foreach ($tourPackage->images as $image) {
            Storage::disk('public')->delete($image->image);
            $image->delete();
        }

        $tourPackage->delete();

        return new ApiResponseResources(true, 'Tour Package Successfully Deleted!', null, 200);
    }
}
