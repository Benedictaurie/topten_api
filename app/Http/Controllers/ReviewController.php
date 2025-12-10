<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Review;
use App\Models\Booking;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\ApiResponseResources;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    /**
     * Get featured reviews for homepage slider
     * Menampilkan maksimal 12 customer yang sudah melakukan touring dan menulis review
     */
    public function index(Request $request)
    {
        try {
            // Optional: filter by package type jika ada parameter
            $packageType = $request->get('package_type'); // tour, activity, rental
            $packageId = $request->get('package_id');
            $limit = $request->get('limit', 12); // Default 12 untuk slider

            $query = Review::with(['user', 'booking.bookable'])
                ->whereHas('booking', function($query) {
                    $query->where('status', 'completed')
                        ->whereHas('transactions', function($q) {
                            $q->where('status', 'paid');
                        });
                })
                ->whereHas('user', function($query) {
                    $query->where('role', 'customer');
                });

            // Filter by package type jika ada
            if ($packageType && $packageId) {
                $modelClass = match($packageType) {
                    'tour' => 'App\Models\TourPackage',
                    'activity' => 'App\Models\ActivityPackage', 
                    'rental' => 'App\Models\RentalPackage',
                    default => null
                };

                if ($modelClass) {
                    $query->whereHas('booking', function($q) use ($modelClass, $packageId) {
                        $q->where('bookable_type', $modelClass)
                        ->where('bookable_id', $packageId);
                    });
                }
            }

            $reviews = $query->orderBy('rating', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function($review) {
                    return [
                        'id' => $review->id,
                        'user_initial' => strtoupper(substr($review->user->name, 0, 1)),
                        'user_name' => $review->user->name,
                        'rating' => $review->rating,
                        'comment' => $review->comment,
                        'image' => $review->image ? Storage::disk('public')->url($review->image) : null,
                        'package_name' => $review->booking->bookable->name ?? 'N/A',
                        'package_type' => $review->booking->package_type,
                        'travel_date' => $review->booking->start_date->format('M Y'),
                        'stars' => $this->generateStars($review->rating),
                    ];
                });

            return new ApiResponseResources(true, 'Reviews retrieved successfully', [
                'total_reviews' => $reviews->count(),
                'average_rating' => round($reviews->avg('rating'), 1),
                'reviews' => $reviews
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to retrieve reviews: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve reviews', null, 500);
        }
    }

    /**
     * Helper method to generate stars HTML/emoji (untuk frontend)
     */
    private function generateStars($rating)
    {
        $stars = '';
        $fullStars = floor($rating);
        
        for ($i = 0; $i < $fullStars; $i++) {
            $stars .= '★';
        }
        
        return $stars;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $bookingId)
    {
        $messages = [
            'rating.required' => 'The rating is required!',
            'rating.numeric' => 'The rating must be a number!',
            'rating.min' => 'The minimum rating is 1!',
            'rating.max' => 'The maximum rating is 5!',
            'image.image' => 'The file must be an image!',
            'image.mimes' => 'The image must be a jpeg, png, jpg, or gif!',
            'image.max' => 'The image may not be greater than 2MB!',
        ];

        $validator = Validator::make($request->all(), [
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ], $messages); 

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $userId = Auth::id();
            
            // Cek apakah booking exists dan milik user yang login
            $booking = Booking::with(['bookable', 'transactions'])
                ->where('id', $bookingId)
                ->where('user_id', $userId)
                ->first();

            if (!$booking) {
                return new ApiResponseResources(false, 'Booking not found or you are not authorized to review this booking', null, 404);
            }

            // Cek apakah booking sudah completed (status completed)
            if ($booking->status !== 'completed') {
                return new ApiResponseResources(false, 'You can only review completed bookings', null, 422);
            }

            // Cek apakah pembayaran sudah paid
            $paidTransaction = $booking->transactions->where('status', 'paid')->first();
            if (!$paidTransaction) {
                return new ApiResponseResources(false, 'You can only review bookings that have been paid', null, 422);
            }

            // Cek apakah sudah ada review untuk booking ini
            $existingReview = Review::where('booking_id', $bookingId)->first();
            if ($existingReview) {
                return new ApiResponseResources(false, 'You have already reviewed this booking', null, 422);
            }

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('reviews', 'public');
            }

            // Create review
            $review = Review::create([
                'user_id' => $userId,
                'booking_id' => $bookingId,
                'rating' => $request->rating,
                'comment' => $request->comment,
                'image' => $imagePath,
            ]);

            // Load relations for response
            $review->load(['user', 'booking.bookable']);

            return new ApiResponseResources(true, 'Review submitted successfully', $review);

        } catch (\Exception $e) {
            \Log::error('Review creation failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to submit review', null, 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $review = Review::with(['user', 'booking.bookable'])->find($id);

            if (!$review) {
                return new ApiResponseResources(false, 'Review not found', null, 404);
            }

            return new ApiResponseResources(true, 'Review retrieved successfully', $review);

        } catch (\Exception $e) {
            \Log::error('Failed to retrieve review: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve review', null, 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $messages = [
            'rating.numeric' => 'The rating must be a number!',
            'rating.min' => 'The minimum rating is 1!',
            'rating.max' => 'The maximum rating is 5!',
            'image.image' => 'The file must be an image!',
            'image.mimes' => 'The image must be a jpeg, png, jpg, or gif!',
            'image.max' => 'The image may not be greater than 2MB!',
        ];

        $validator = Validator::make($request->all(), [
            'rating' => 'nullable|numeric|min:1|max:5',
            'comment' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ], $messages); 

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $userId = Auth::id();
            $review = Review::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$review) {
                return new ApiResponseResources(false, 'Review not found or you are not authorized to update this review', null, 404);
            }

            // Update only provided fields
            if ($request->has('rating')) {
                $review->rating = $request->rating;
            }
            if ($request->has('comment')) {
                $review->comment = $request->comment;
            }

            // Handle image update
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($review->image && Storage::disk('public')->exists($review->image)) {
                    Storage::disk('public')->delete($review->image);
                }
                $review->image = $request->file('image')->store('reviews', 'public');
            }

            $review->save();
            $review->load(['user', 'booking.bookable']);

            return new ApiResponseResources(true, 'Review updated successfully', $review);

        } catch (\Exception $e) {
            \Log::error('Review update failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to update review', null, 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(string $id)
    {
        try {
            $userId = Auth::id();
            $review = Review::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$review) {
                return new ApiResponseResources(false, 'Review not found or you are not authorized to delete this review', null, 404);
            }

            // Delete image if exists
            if ($review->image && Storage::disk('public')->exists($review->image)) {
                Storage::disk('public')->delete($review->image);
            }

            $review->delete();

            return new ApiResponseResources(true, 'Review deleted successfully', null);

        } catch (\Exception $e) {
            \Log::error('Review deletion failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to delete review', null, 500);
        }
    }


    /**
     * Get user's reviews
     */
    public function myReviews()
    {
        try {
            $userId = Auth::id();
            $reviews = Review::where('user_id', $userId)
                ->with(['booking.bookable'])
                ->orderBy('created_at', 'desc')
                ->get();

            return new ApiResponseResources(true, 'Your reviews retrieved successfully', $reviews);

        } catch (\Exception $e) {
            \Log::error('Failed to retrieve user reviews: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve your reviews', null, 500);
        }
    }

    /**
     * Check if user can review a booking
     * Untuk tombol "Write a Review" di frontend
     */
    public function canReviewBooking($bookingId)
    {
        try {
            $userId = Auth::id();
            
            $booking = Booking::with(['transactions'])
                ->where('id', $bookingId)
                ->where('user_id', $userId)
                ->first();

            if (!$booking) {
                return new ApiResponseResources(false, 'Booking not found', null, 404);
            }

            $canReview = (
                $booking->status === 'completed' && 
                $booking->transactions->where('status', 'paid')->isNotEmpty() &&
                !Review::where('booking_id', $bookingId)->exists()
            );

            return new ApiResponseResources(true, 'Review eligibility checked', [
                'can_review' => $canReview,
                'booking' => [
                    'id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'package_name' => $booking->bookable->name ?? 'N/A',
                    'package_type' => $booking->package_type,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to check review eligibility: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to check review eligibility', null, 500);
        }
    }


    /**
     * ADMIN: Get all reviews with filters
     */
    public function adminIndex(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $rating = $request->get('rating');
            $packageType = $request->get('package_type');

            $query = Review::with(['user', 'booking.bookable'])
                ->orderBy('created_at', 'desc');

            // Filters
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('comment', 'like', "%{$search}%")
                    ->orWhereHas('user', function($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
                });
            }

            if ($rating) {
                $query->where('rating', $rating);
            }

            if ($packageType) {
                $modelClass = match($packageType) {
                    'tour' => 'App\Models\TourPackage',
                    'activity' => 'App\Models\ActivityPackage', 
                    'rental' => 'App\Models\RentalPackage',
                    default => null
                };
                
                if ($modelClass) {
                    $query->whereHas('booking', function($q) use ($modelClass) {
                        $q->where('bookable_type', $modelClass);
                    });
                }
            }

            $reviews = $query->paginate($perPage);

            $stats = [
                'total_reviews' => Review::count(),
                'average_rating' => round(Review::avg('rating'), 1),
                'rating_distribution' => [
                    5 => Review::where('rating', 5)->count(),
                    4 => Review::where('rating', 4)->count(),
                    3 => Review::where('rating', 3)->count(),
                    2 => Review::where('rating', 2)->count(),
                    1 => Review::where('rating', 1)->count(),
                ]
            ];

            return new ApiResponseResources(true, 'Admin reviews retrieved successfully', [
                'reviews' => $reviews,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            \Log::error('Admin reviews retrieval failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve reviews', null, 500);
        }
    }

    /**
     * ADMIN: Get specific review details
     */
    public function adminShow($id)
    {
        try {
            $review = Review::with(['user', 'booking.bookable', 'booking.transactions'])
                ->find($id);

            if (!$review) {
                return new ApiResponseResources(false, 'Review not found', null, 404);
            }

            return new ApiResponseResources(true, 'Review details retrieved successfully', $review);

        } catch (\Exception $e) {
            \Log::error('Admin review details failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve review details', null, 500);
        }
    }

    /**
     * ADMIN: Delete review
     */
    public function adminDelete($id)
    {
        try {
            $review = Review::find($id);

            if (!$review) {
                return new ApiResponseResources(false, 'Review not found', null, 404);
            }

            // Delete image if exists
            if ($review->image && Storage::disk('public')->exists($review->image)) {
                Storage::disk('public')->delete($review->image);
            }

            $review->delete();

            return new ApiResponseResources(true, 'Review deleted successfully', null);

        } catch (\Exception $e) {
            \Log::error('Admin review deletion failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to delete review', null, 500);
        }
    }
}