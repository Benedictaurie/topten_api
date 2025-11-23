<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\ApiResponseResources;
use App\Models\RentalPackage;
// use App\Models\ImagePackage;
use App\Services\Availability\RentalAvailabilityService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class RentalPackageController extends Controller
{
    protected $rentalAvailabilityService;

    // 1. Dependency Injection di Constructor
    public function __construct(RentalAvailabilityService $rentalAvailabilityService)
    {
        $this->rentalAvailabilityService = $rentalAvailabilityService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $packages = RentalPackage::where('is_available', true)->paginate(2);
        $packages->load('images');
        return new ApiResponseResources(true, 'Rental packages retrieved successfully', $packages);
    }

    /**
     * Check availability for specific rental
     */
    public function checkAvailability(Request $request, $id)
    {
        // Validasi: Perlu tanggal mulai dan tanggal akhir
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        // Panggil Service Class
        $isAvailable = $this->rentalAvailabilityService->checkAvailability(
            $id,
            $request->start_date,
            $request->end_date
        );

        // Jika tersedia, hitung juga harganya
        $calculatedPrice = 0;
        if ($isAvailable) {
            $calculatedPrice = $this->rentalAvailabilityService->calculatePrice(
                $id,
                $request->start_date,
                $request->end_date
            );
        }

        return new ApiResponseResources(true, 'Availability checked successfully', [
            'available' => $isAvailable,
            'rental_id' => $id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'price' => $calculatedPrice 
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
            'type.required' => 'Type is required (motor/mobil)',
            'brand.required' => 'Brand name is required, example: Yamaha.',
            'model.required' => 'Model name is required, example: Mio Z',
            'plate_number.required' => 'Plate number is required',
            'description.required' => 'Fill the description here',
            'price_per_day.required' => 'Price per day is required',
            'price_per_day.numeric' => 'The price must be entered as a number',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image'=> 'The uploaded file must be an image (jpeg, png, jpg, gif, or svg).',
            'image.*.mimes'=> 'The image file format is invalid. Only :values formats are allowed.',
            'image.*.max' => 'The image file size may not exceed :max kilobytes.',
        ];

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:50',
            'brand' => 'required|string|max:50',
            'model' => 'required|string|max:50',
            'plate_number' => 'required|string|max:50|unique:rental_packages,plate_number',
            'description' => 'required',
            'price_per_day' => 'required|numeric',
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $rentalPackage = RentalPackage::create([
            'type' => $request->type,
            'brand' => $request->brand,
            'model' => $request->model,
            'plate_number' => $request->plate_number,
            'description' => $request->description,
            'price_per_day' => $request->price_per_day,
            'is_available' => $request->is_available ?? true
        ]);

        if ($request->hasFile("image")) {
            foreach ($request->file('image') as $imageFile) {
                $path = $imageFile->store('packages/rental', 'public'); 
                $rentalPackage->images()->create(['image' => $path]);
            }
        }

        if (!$rentalPackage) {
            return new ApiResponseResources(false, 'Failed to save Rental Package!', null, 500);
        }

        return new ApiResponseResources(true, 'Rental Package Saved Successfully!', $rentalPackage->load('images'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $rentalPackage = RentalPackage::find($id);

        if (!$rentalPackage) {
            return new ApiResponseResources(false, 'Rental Package Not Found!', null, 404);
        }
        return new ApiResponseResources(true, 'Rental Package Found!', $rentalPackage->load('images'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $rentalPackage = RentalPackage::find($id);

        if (!$rentalPackage) {
            return new ApiResponseResources(false, 'Rental Package Not Found!', null, 404);
        }

        $messages =[
            'name.required' => 'Name is required',
            'name.max' => 'Name may not be greater than 255 characters!',
            'type.required' => 'Type is required (motor/mobil)',
            'brand.required' => 'Brand name is required, example: Yamaha.',
            'model.required' => 'Model name is required, example: Mio Z',
            'plate_number.required' => 'Plate number is required',
            'description.required' => 'Fill the description here',
            'price_per_day.required' => 'Price per day is required',
            'price_per_day.numeric' => 'The price must be entered as a number',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image'=> 'The uploaded file must be an image (jpeg, png, jpg, gif, or svg).',
            'image.*.mimes'=> 'The image file format is invalid. Only :values formats are allowed.',
            'image.*.max' => 'The image file size may not exceed :max kilobytes.',
        ];

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:50',
            'brand' => 'required|string|max:50',
            'model' => 'required|string|max:50',
            'plate_number' => 'required|string|max:50|unique:rental_packages,plate_number,' . $id,
            'description' => 'required',
            'price_per_day' => 'required|numeric',
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $rentalPackage->update([
            'type' => $request->type,
            'brand' => $request->brand,
            'model' => $request->model,
            'plate_number' => $request->plate_number,
            'description' => $request->description,
            'price_per_day' => $request->price_per_day,
            'is_available' => $request->is_available ?? true
        ]);

        // Logika Gambar (sama seperti Activity)
        if ($request->hasFile("image")) {
            foreach ($rentalPackage->images as $oldImage) {
                Storage::disk('public')->delete($oldImage->image);
                $oldImage->delete();
            }
            foreach ($request->file('image') as $imageFile) {
                $path = $imageFile->store('packages/rental', 'public'); 
                $rentalPackage->images()->create(['image' => $path]);
            }
        }

        return new ApiResponseResources(true, 'Rental Package Successfully Updated!', $rentalPackage->load('images'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(string $id)
    {
        $rentalPackage = RentalPackage::find($id);

        if (!$rentalPackage) {
            return new ApiResponseResources(false, 'Rental Package Not Found!', null, 404);
        }

        // Hapus gambar dari storage dan database
        foreach ($rentalPackage->images as $image) {
            Storage::disk('public')->delete($image->image);
            $image->delete();
        }

        $rentalPackage->delete();

        return new ApiResponseResources(true, 'Rental Package Successfully Deleted!', null, 200);
    }
}
