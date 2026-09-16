<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminUploadController extends Controller
{
    /**
     * Store an uploaded image (used by the rich editor to insert images into content).
     */
    public function image(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:6144'],
        ]);

        $path = $request->file('image')->store('uploads', 'public');

        return response()->json(['url' => asset('storage/'.$path)]);
    }
}
