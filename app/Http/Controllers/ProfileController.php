<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    // Get the logged-in student's profile
    public function show(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'user' => $request->user(),
        ], 200);
    }

    // Update the logged-in student's profile
    public function update(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'sometimes|required|string|max:255',
            'academic_major' => 'sometimes|nullable|string|max:255',
        ]);

        if (empty($validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide at least one field to update.',
            ], 422);
        }

        $request->user()->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user' => $request->user()->fresh(),
        ], 200);
    }
}
