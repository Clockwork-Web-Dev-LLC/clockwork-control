<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class StyleguideController extends Controller
{
    /**
     * Display the design system styleguide.
     */
    public function index(): View
    {
        return view('styleguide.index');
    }
}
