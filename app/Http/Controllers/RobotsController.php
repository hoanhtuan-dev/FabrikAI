<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function index(): Response
    {
        $content = "User-agent: *\n"
            ."Disallow: /admin\n"
            ."Disallow: /gio-hang\n"
            ."Disallow: /thanh-toan\n"
            ."Disallow: /dang-nhap\n"
            ."Disallow: /dang-ky\n"
            ."Disallow: /tai-khoan\n"
            ."Disallow: /yeu-thich\n"
            ."Allow: /\n"
            ."\n"
            ."Sitemap: ".route('sitemap')."\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
