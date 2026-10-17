<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Lecturer;
use App\Services\LecturerDuplicateMergeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SuperadminDuplicateController extends Controller
{
    public function index(LecturerDuplicateMergeService $merges): View
    {
        $candidates = $merges->candidates();

        return view('lpm.superadmin.duplicates.index', compact('candidates'));
    }

    public function preview(Lecturer $keep, Lecturer $remove, LecturerDuplicateMergeService $merges): View
    {
        $impact = $merges->preview($keep, $remove);

        return view('lpm.superadmin.duplicates.preview', compact('keep', 'remove', 'impact'));
    }

    public function merge(Request $request, Lecturer $keep, Lecturer $remove, LecturerDuplicateMergeService $merges): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'confirm_name' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        if ($data['confirm_name'] !== $remove->name || ! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['confirm_name' => 'Nama konfirmasi atau kata sandi tidak sesuai.']);
        }
        $merges->merge($keep, $remove, $data['fingerprint'], $request->user());

        return redirect()->route('superadmin.duplicates.index')->with('success', 'Identitas dosen berhasil digabung.');
    }
}
