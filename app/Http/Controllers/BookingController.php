<?php

namespace App\Http\Controllers;

use App\Models\TourPackage;
use App\Models\ActivityPackage;
use App\Models\RentalPackage;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth; 
use Carbon\Carbon; // Tambahkan ini untuk manipulasi tanggal

class BookingController extends Controller
{
    /**
     * Menampilkan form booking untuk paket tertentu.
     * URL: /book/{type}/{id} -> (contoh: /book/tour/1)
     */
    public function create($type, $id)
    {
        $model = match ($type) {
            'tour' => TourPackage::class,
            'activity' => ActivityPackage::class,
            'rental' => RentalPackage::class,
            default => abort(404)
        };

        $package = $model::findOrFail($id);

        return view('bookings.create', [
            'package' => $package,
            'package_type' => $type,
        ]);
    }

    /**
     * Menyimpan data booking baru ke database.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'package_type' => 'required|in:tour,activity,rental',
            'package_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'start_date' => 'required|date|after_or_equal:today',
            // Untuk rental, kita butuh input end_date
            'end_date' => 'required_if:package_type,rental|date|after_or_equal:start_date',
            'notes' => 'nullable|string|max:500',
        ]);

        $packageModel = match($validated['package_type']) {
            'tour' => TourPackage::class,
            'activity' => ActivityPackage::class,
            'rental' => RentalPackage::class,
        };

        $package = $packageModel::findOrFail($validated['package_id']);
        
        // --- LOGIKA HARGA ---
        // Ambil nama kolom harga yang benar sesuai tipe paket
        $unitPrice = $package->price_per_person ?? $package->price_per_day;
        
        $totalPrice = $unitPrice * $validated['quantity'];

        // --- LOGIKA END_DATE ---
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = null;

        if ($validated['package_type'] === 'tour') {
            // Untuk tour, hitung end_date berdasarkan duration_days
            $endDate = $startDate->copy()->addDays($package->duration_days - 1);
        } elseif ($validated['package_type'] === 'rental') {
            // Untuk rental, ambil dari input form
            $endDate = Carbon::parse($validated['end_date']);
        }
        // Untuk activity, end_date bisa diisi null atau sama dengan start_date karena pakai hitungan waktu

        $booking = Booking::create([
            'booking_code' => 'BK-' . strtoupper(Str::random(8)),
            'user_id' => auth()->id(),
            'bookable_id' => $package->id,
            'bookable_type' => $packageModel,
            'quantity' => $validated['quantity'],
            'start_date' => $startDate,
            'end_date' => $endDate, 
            'unit_price_at_booking' => $unitPrice,
            'total_price' => $totalPrice,
            'notes' => $validated['notes'],
            'status' => 'pending',
        ]);

        return redirect()->route('booking.success', $booking)
                         ->with('success', 'Booking berhasil! Silakan lanjutkan ke pembayaran.');
    }

    /**
     * Menampilkan halaman sukses setelah booking.
     */
    public function success(Booking $booking)
    {
        // --- SECURITY ---
        // Memastikan hanya user yang membuat booking yang bisa lihat halaman ini
        if (Auth::id() !== $booking->user_id) {
            abort(403); // Akses ditolak
        }

        $booking->load('bookable');
        
        return view('bookings.success', [
            'booking' => $booking,
        ]);
    }

    /**
     * Menampilkan riwayat booking untuk user yang sedang login.
     */
    public function history()
    {
        $bookings = Booking::where('user_id', auth()->id())
                            ->with('bookable')
                            ->latest()
                            ->paginate(10);

        return view('users.booking_history', compact('bookings'));
    }
}