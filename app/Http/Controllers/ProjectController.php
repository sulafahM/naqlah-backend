<?php

namespace App\Http\Controllers;


use App\Models\PracticalWork;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    // Create a new project
    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'nullable|integer|exists:university_courses,course_id',
            'work_type' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'github_url' => 'nullable|url|max:255',
        ]);

        $project = PracticalWork::create([
            ...$validated,
            'user_id' => $request->user()->user_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Project created successfully.',
            'project' => $project,
        ], 201);
    }

    // Get all projects belonging to the logged-in student
    public function index(Request $request)
    {
        $projects = PracticalWork::where(
            'user_id',
            $request->user()->user_id
        )->get();

        return response()->json([
            'success' => true,
            'message' => 'Projects retrieved successfully.',
            'projects' => $projects,
        ], 200);
    }

    // Get one project
    public function show(Request $request, $id)
    {
        $project = PracticalWork::where(
            'user_id',
            $request->user()->user_id
        )->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Project retrieved successfully.',
            'project' => $project,
        ], 200);
    }

    // Update a project
    public function update(Request $request, $id)
    {
        $project = PracticalWork::where(
            'user_id',
            $request->user()->user_id
        )->findOrFail($id);

        $validated = $request->validate([
            'course_id' => 'sometimes|nullable|integer|exists:university_courses,course_id',
            'work_type' => 'sometimes|required|string|max:255',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'github_url' => 'sometimes|nullable|url|max:255',
        ]);

        if (empty($validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide at least one field to update.',
            ], 422);
        }

        $project->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Project updated successfully.',
            'project' => $project->fresh(),
        ], 200);
    }

    // Delete a project
    public function destroy(Request $request, $id)
    {
        $project = PracticalWork::where(
            'user_id',
            $request->user()->user_id
        )->findOrFail($id);

        $project->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project deleted successfully.',
        ], 200);
    }
}
