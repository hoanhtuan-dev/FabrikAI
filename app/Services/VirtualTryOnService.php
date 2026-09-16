<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Thay Đổi Người Mẫu" (Click-to-Swap).
 *
 * The DashScope virtual-try-on endpoints are NOT available on this account (they always return
 * "Model not exist"), so the whole swap is driven by the Qwen image-edit model (qwen-image-edit /
 * qwen-image-edit-plus…): we re-render the person in the design image to match the chosen model
 * face, keeping the garment 100% unchanged. Optional inputs: background, pose reference.
 */
class VirtualTryOnService
{
    /** The image model that actually produced the last swap (may differ from the configured swap_model). */
    public ?string $lastModel = null;

    /** Number of real model calls made for the last swap (2 for a face-ref 2-step swap, 1 otherwise). */
    protected int $calls = 0;

    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function modelCatalog(): array
    {
        // Manageable face presets (DB) — the swap picker + backend both read from here, so presets can
        // be added/edited in Studio Settings. When the table is empty we fall back to built-in presets.
        $db = \App\Models\FacePreset::orderBy('sort')->orderBy('id')->get(); // hiện mọi preset (khớp Settings)
        if ($db->isNotEmpty()) {
            return $db->map(function ($p) {
                return [
                    'id' => 'fp'.$p->id,
                    'name' => $p->name,
                    'ethnicity' => $p->ethnicity ?: 'Vietnamese female',
                    'image' => studio_image_url($p->image), // may be null (text-only preset)
                    'thumb' => studio_image_thumb_url($p->image),
                    'desc' => $p->description,
                    'preset' => true,
                ];
            })->values()->all();
        }

        return array_map(function ($m) {
            $m['image'] = studio_image_url($m['image']);
            $m['thumb'] = studio_image_thumb_url($m['image'] ?? null);
            return $m;
        }, $this->builtinFacePresets());
    }

    /**
     * Built-in fallback presets (10 young Vietnamese female looks) — used only when the DB is empty.
     */
    public function builtinFacePresets(): array
    {
        return [
            ['id' => 'vp01', 'name' => 'Nhẹ nhàng tự nhiên', 'ethnicity' => 'Vietnamese female', 'image' => '/storage/studio/khuon-mat/model-01.png', 'desc' => 'young Vietnamese woman, 22, light natural everyday makeup, shoulder-length straight black hair, fair skin, gentle warm smile, soft feminine features'],
            ['id' => 'vp02', 'name' => 'Tóc dài lượn sóng', 'ethnicity' => 'Vietnamese female', 'image' => '/storage/studio/khuon-mat/model-02.png', 'desc' => 'young Vietnamese woman, 24, long wavy black hair with soft curls, radiant clear skin, subtle Korean-style makeup, elegant and graceful'],
            ['id' => 'vp03', 'name' => 'Cá tính tóc bob', 'ethnicity' => 'Vietnamese female', 'image' => '/storage/studio/khuon-mat/model-03.png', 'desc' => 'young Vietnamese woman, 23, chic short black bob haircut, bold natural lipstick, confident modern look, sharp jawline, almond eyes'],
            ['id' => 'vp04', 'name' => 'Thanh lịch tóc búi', 'ethnicity' => 'Vietnamese female', 'image' => '/storage/studio/khuon-mat/model-04.png', 'desc' => 'young Vietnamese woman, 25, elegant low bun hairstyle, minimalist makeup, classic Vietnamese beauty, refined and sophisticated'],
            ['id' => 'vp05', 'name' => 'Năng động tóc đuôi ngựa', 'ethnicity' => 'Vietnamese female', 'image' => '/storage/studio/khuon-mat/model-05.png', 'desc' => 'young Vietnamese woman, 22, high ponytail, fresh sporty energetic look, clear glowing skin, bright happy smile, youthful'],
            ['id' => 'vp06', 'name' => 'Ngọt ngào tóc xoăn', 'ethnicity' => 'Vietnamese female', 'image' => '/storage/studio/khuon-mat/model-06.png', 'desc' => 'young Vietnamese woman, 21, soft loose curls, sweet innocent face, rosy cheeks, gentle dreamy eyes, cute and charming'],
            ['id' => 'vp07', 'name' => 'Sang trọng mái lệch', 'ethnicity' => 'Vietnamese female', 'image' => null, 'desc' => 'young Vietnamese woman, 26, side-swept bangs, sophisticated editorial makeup, elegant high-fashion look, striking features'],
            ['id' => 'vp08', 'name' => 'Thời trang mắt khói', 'ethnicity' => 'Vietnamese female', 'image' => null, 'desc' => 'young Vietnamese woman, 24, sleek center-parted straight hair, trendy smoky-eye makeup, fashion-forward street style, confident gaze'],
            ['id' => 'vp09', 'name' => 'Dịu dàng tóc đen thẳng', 'ethnicity' => 'Vietnamese female', 'image' => null, 'desc' => 'young Vietnamese woman, 23, long straight jet-black hair, fresh natural no-makeup look, serene calm expression, classic beauty'],
            ['id' => 'vp10', 'name' => 'Hiện đại mái ngố', 'ethnicity' => 'Vietnamese female', 'image' => null, 'desc' => 'young Vietnamese woman, 22, modern curtain bangs, fresh glass-skin makeup, trendy K-pop inspired look, sparkling eyes'],
        ];
    }

    /**
     * Fall back to the built-in sample photo for a DB face preset that has no image, so the face
     * reference is still sent to the edit model (face fidelity from text alone is poor).
     */
    protected function builtinFaceImageByName(?string $name): ?string
    {
        foreach ($this->builtinFacePresets() as $bp) {
            if (($bp['name'] ?? '') === $name) {
                return $bp['image'] ?? null;
            }
        }
        return null;
    }

    public function poseCatalog(): array
    {
        // Manageable pose presets (DB) — read by the swap picker + backend. Falls back to built-ins.
        $db = \App\Models\PosePreset::orderBy('sort')->orderBy('id')->get(); // hiện mọi preset (khớp Settings)
        if ($db->isNotEmpty()) {
            return $db->map(function ($p) {
                return [
                    'id' => 'pp'.$p->id,
                    'name' => $p->name,
                    'skeleton' => $p->description,
                    'image' => studio_image_url($p->image ?: $this->builtinPoseImageByName($p->name)),
                    'thumb' => studio_image_thumb_url($p->image ?: $this->builtinPoseImageByName($p->name)),
                    'preset' => true,
                ];
            })->values()->all();
        }

        return array_map(function ($p) {
            $p['image'] = studio_image_url($p['image']);
            $p['thumb'] = studio_image_thumb_url($p['image'] ?? null);
            return $p;
        }, $this->builtinPosePresets());
    }

    /**
     * Fall back to the built-in sample image for a DB pose preset that has no image, so the pose
     * reference is still sent to the edit model (a pose described only in text is rarely applied).
     */
    protected function builtinPoseImageByName(?string $name): ?string
    {
        foreach ($this->builtinPosePresets() as $bp) {
            if (($bp['name'] ?? '') === $name) {
                return $bp['image'] ?? null;
            }
        }
        return null;
    }

    public function builtinPosePresets(): array
    {
        // Pose chỉ mô tả TƯ THẾ (không lẫn phom người) — phom người đưa riêng vào prompt (physique).
        return [
            ['id' => 'pose01', 'name' => 'Đứng thẳng', 'skeleton' => 'standing straight and elegant, arms relaxed at sides, weight even, one foot slightly forward, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-01.png'],
            ['id' => 'pose02', 'name' => 'Tay chống hông', 'skeleton' => 'standing with one hand on hip, weight on one leg, chin slightly up, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-02.png'],
            ['id' => 'pose03', 'name' => 'Sải bước catwalk', 'skeleton' => 'walking mid-stride catwalk, one hand on hip, dynamic confident stride, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-03.png'],
            ['id' => 'pose04', 'name' => 'Nghiêng người', 'skeleton' => 'standing at a slight angle, one shoulder forward, one hand lightly brushing hair, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-04.png'],
            ['id' => 'pose05', 'name' => 'Ngồi ghế', 'skeleton' => 'sitting gracefully on a high stool, back straight, one leg extended, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-05.png'],
            ['id' => 'pose06', 'name' => 'Tay đút túi', 'skeleton' => 'standing in relaxed side profile, one hand in pocket, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-06.png'],
            ['id' => 'pose07', 'name' => 'Xoay lưng', 'skeleton' => 'back view turned away, glancing over one shoulder, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-07.png'],
            ['id' => 'pose08', 'name' => 'Tựa tường', 'skeleton' => 'leaning lightly against a wall, one leg bent, arms relaxed, full body head to toe', 'image' => '/storage/studio/dang-nguoi-mau/pose-08.png'],
        ];
    }

    /**
     * Resolve a model by id. Custom faces added in /studio/assets (type=model) are looked up first,
     * so a personally-added face actually works (not silently replaced by catalog[0]).
     */
    public function pickModel(string $id): ?array
    {
        // Manageable DB preset (id like "fp3").
        if (str_starts_with($id, 'fp')) {
            $p = \App\Models\FacePreset::find((int) substr($id, 2));
            if ($p) {
                return [
                    'id' => 'fp'.$p->id,
                    'name' => $p->name,
                    'image' => $p->image ?: $this->builtinFaceImageByName($p->name),
                    'ethnicity' => $p->ethnicity ?: 'Vietnamese female',
                    'desc' => $p->description,
                    'preset' => true,
                ];
            }
        }
        // Custom asset (added via the swap card's "➕ Thêm khuôn mặt").
        if ($id !== '' && is_numeric($id)) {
            $asset = \App\Models\StudioAsset::where('type', 'model')->where('id', $id)->first();
            if ($asset) {
                return [
                    'id' => (string) $asset->id,
                    'name' => $asset->name ?: 'model',
                    'image' => $asset->path,
                    'ethnicity' => '',
                    'desc' => 'a person matching the provided reference photo',
                ];
            }
        }
        // Built-in presets (vp01..vp10).
        foreach ($this->builtinFacePresets() as $m) {
            if (($m['id'] ?? '') === $id) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Resolve a pose by id. Custom poses added in /studio/assets (type=pose) are looked up first.
     */
    public function pickPose(string $id): ?array
    {
        // Manageable DB preset (id like "pp3").
        if (str_starts_with($id, 'pp')) {
            $p = \App\Models\PosePreset::find((int) substr($id, 2));
            if ($p) {
                return [
                    'id' => 'pp'.$p->id,
                    'name' => $p->name,
                    'skeleton' => $p->description,
                    'image' => $p->image ?: $this->builtinPoseImageByName($p->name),
                    'preset' => true,
                ];
            }
        }
        // Custom asset (added via the swap card's "➕ Thêm dáng").
        if ($id !== '' && is_numeric($id)) {
            $asset = \App\Models\StudioAsset::where('type', 'pose')->where('id', $id)->first();
            if ($asset) {
                return [
                    'id' => (string) $asset->id,
                    'name' => $asset->name ?: 'pose',
                    'image' => $asset->path,
                    'skeleton' => ($asset->name ?: 'in a confident pose'),
                ];
            }
        }
        foreach ($this->builtinPosePresets() as $p) {
            if (($p['id'] ?? '') === $id) {
                return $p;
            }
        }
        return null;
    }

    /**
     * "Mặc thử đồ" (Click-to-Swap) — Qwen image-edit, nhiều pass.
     *
     * PASS 1 (cốt lõi): đổi DÁNG/cơ thể, LUÔN giữ nguyên khuôn mặt gốc + trang phục (họa tiết, vân
     * vải). KHÔNG nhét mô tả "cả người" vào PASS 1 — điều đó khiến model vẽ lại người mới (mất đồ).
     * PASS 1b (chỉ khi $changeFace): đổi RIÊNG khuôn mặt bằng ẢNH tham chiếu (mặt được chọn).
     * PASS 2 (nếu có $background): đổi hậu cảnh, giữ nguyên người + trang phục.
     * Pose được mô tả bằng văn bản ($pose skeleton); ảnh pose ref không gửi vì từng làm model bỏ
     * qua pose. $tone là hiệu ứng màu ở mức prompt.
     */
    public function fallbackEdit(string $designImage, string $modelDesc, string $pose, string $background = '', ?string $faceRefUrl = null, string $tone = 'none', ?string $poseRefUrl = null, bool $changeFace = false): ?string
    {
        // $changeFace=false: GIỮ NGUYÊN khuôn mặt gốc. $changeFace=true: đổi mặt theo người mẫu (PASS 1b).
        $swapModel = studio_swap_model();
        $this->calls = 0; // bộ đếm cộng dồn: PASS1 candidates + face + background
        $this->lastModel = null;

        // Fixed body-proportion descriptor at the default build=6 (the slider was removed; this is the
        // EXACT clause from the confirmed-good version where the pose was applied correctly).
        $proportion = $this->buildEnglish(6);
        $wantBg = $background && strtolower($background) !== 'keep' && strtolower($background) !== 'original';
        $toneS = $this->toneInstruction($tone, $background);

        // Garment = PRODUCT to preserve EXACTLY (dominant directive), then replace the person with
        // the text-described model in the text-described pose. Single image + clear text = reliable.
        // A background-replacement clause is intentionally NOT here: inside the same call it competes
        // with the pose instruction — with a complex background the model redraws the scene and
        // IGNORES the pose (simple/white backgrounds don't trigger this because they are trivial to
        // regenerate). The background is applied in a separate PASS 2 below.
        // CỐT LÕI "Mặc thử đồ": PASS 1 LUÔN giữ nguyên khuôn mặt gốc + trang phục, chỉ đổi dáng/cơ thể.
        // Việc đổi khuôn mặt làm RIÊNG ở PASS 1b bằng ẢNH tham chiếu — không nhét mô tả "cả người" vào
        // PASS 1, vì điều đó khiến model vẽ lại cả người + trang phục (thành "tạo ảnh mới" thay vì mặc thử đồ).
        // ===== Vai trò các ảnh trong content (thứ tự editImage: [faceRef?, poseRef?, source]) =====
        // Mặc định GỘP đổi mặt vào 1 pass (swap_face_inline=true) để NHANH: PASS 1 nhận faceRef + source,
        // bỏ PASS 1b riêng. Đặt false để quay lại 2-pass (chất lượng mặt có thể tốt hơn nhưng chậm gấp đôi).
        $inlineFace = $changeFace && (bool) studio_config('swap_face_inline', true) && $faceRefUrl !== '';
        // Gửi kèm ảnh dáng tham chiếu (pose ref) để TƯ THẾ được áp dụng thực sự.
        $sendPoseImg = (bool) studio_config('swap_pose_image', true) && $poseRefUrl !== '';

        // Vị trí các ảnh trong content editImage: [faceRef?, poseRef?, source]
        $facePos = $inlineFace ? 'the FIRST image' : '';
        $posePos = $sendPoseImg ? ($inlineFace ? 'the SECOND image' : 'the FIRST image') : '';
        $srcPos = 'the image';
        if ($inlineFace && $sendPoseImg) { $srcPos = 'the THIRD image'; }
        elseif ($inlineFace || $sendPoseImg) { $srcPos = 'the SECOND image'; }

        $poseHint = $sendPoseImg
            ? 'A pose reference image is '.$posePos.' (a model demonstrating the target pose). Reproduce the EXACT body pose, stance, arm/leg placement and posture from '.$posePos.', while wearing the garment/accessories from '.$srcPos.' — do NOT copy the garment or the person from the pose image. '
            : '';

        if ($inlineFace) {
            // 1 pass: dress model + apply face from reference, giữ nguyên trang phục/phụ kiện/mũ.
            $physique = 'a stylish, edgy young Vietnamese fashion model with a slim figure, slim waist, long toned legs and a beautiful delicate face';
            $personClause = 'Dress a full-body model ('.$physique.') in the EXACT clothing and accessories shown in '.$srcPos.'. The face MUST be the face from '.$facePos.' (reference face) — do NOT change or generate a different face. Pose EXACTLY: '.$pose.'. ';
        } else {
            $personClause = 'Dress a full-body fashion model in the clothing and ACCESSORIES shown in '.$srcPos.', reproduce this pose EXACTLY: '.$pose.' — body posture, stance, arm/leg placement and facing direction must match this pose precisely. '
                .'The outfit and EVERY accessory must appear on the model EXACTLY as shown — identical colors (exact hue, saturation and brightness, no color shift), prints and patterns, and each accessory correctly placed (shoes on feet, handbag on shoulder or in hand, watch on wrist, earrings on ears, belt at waist, hat on head); do NOT omit ANY accessory. '
                .'Do NOT redesign, replace, or omit any garment or accessory. '
                .'Keep the face of the person in the image unchanged (facial features, hairstyle); do NOT swap or restyle the face. ';
            if ($changeFace && ! $faceRefUrl && $modelDesc !== '') {
                // Preset mặt không có ảnh: đổi mặt NHẸ bằng văn bản (chỉ mặt, giữ mọi thứ khác).
                $personClause .= 'Change ONLY the person\'s face to match this description: '.$modelDesc.'. Keep the hairstyle, head shape, body, pose, and the EXACT outfit + accessories unchanged. ';
            }
        }

        // KHÔNG có bất kỳ prompt/hậu kỳ "vân vải/texture" nào — model chỉ cần sao chép nguyên bản vải từ ảnh nguồn.
        $garmentLock = 'Wear the EXACT garment and every accessory shown in the source image — identical colors, prints, patterns and silhouette. Do NOT change, add, remove, or redesign any garment or accessory. ';

        // Negative prompt (đính vào văn bản — model edit không có trường negative riêng): tăng độ
        // sắc nét, tránh mặt méo/lệch tỷ lệ và vải bị "airbrush" mất vân.
        $negativeClause = 'Avoid: cropped body, wrong face, wrong pose, extra garments or accessories not in the source, wrong colors, deformed hands, blurry, low quality.';
        $fullBodyClause = 'FULL BODY from head to toe, not cropped; model occupies about 75-80% of frame height with headroom above and footroom below. ';

        $instr = $garmentLock
            .$personClause
            .$poseHint
            .'Render a vertically-balanced figure: '.$proportion.', with natural, elongated model proportions (long legs, about 1:7.5 head-to-body) — do NOT make the figure short, squat, compressed or stubby. '
            .'Keep the background of the original image unchanged. '
            .($wantBg ? '' : $toneS).' '.$negativeClause.' '.$fullBodyClause
            .' Photorealistic, full body, studio quality, high fashion, consistent lighting.';

        $imageSvc = app(ImageAIService::class);

        // PASS 1 — đổi dáng/cơ thể, giữ khuôn mặt gốc + trang phục. Model không deterministic nên
        // thử tối đa 3 biến thể prompt; sinh thêm bản để chọn bản đẹp nhất (best-of-N ở dưới).
        $variants = [
            $instr,
            // Variant 2: emphasise garment preservation first, then person replacement
            'PRESERVE the garment EXACTLY: keep every detail of the clothing — colors, patterns, prints, seams, folds, silhouette, length and fabric — 100% unchanged. '
                .'Change ONLY the person wearing it: '.$personClause.$poseHint
                .'Render a vertically-balanced figure: '.$proportion.', with natural, elongated model proportions. '
                .'Keep the background of the original image unchanged. '
                .($wantBg ? '' : $toneS).' '.$negativeClause.' '.$fullBodyClause.' Photorealistic, full body, studio quality, high fashion.',
            // Variant 3: direct instruction style
            'Keep the outfit identical. '.$personClause.$poseHint
                .$proportion.'. Do not change the background. '
                .($wantBg ? '' : $toneS).' '.$negativeClause.' '.$fullBodyClause.' Photorealistic fashion editorial.',
        ];

        // Best-of-N: model edit không deterministic — sinh tối đa N candidates rồi chọn bản đẹp
        // nhất bằng vision QA (so với ảnh thiết kế gốc). Không có key vision thì chỉ sinh 1 bản
        // để không lãng phí lượt gọi.
        $hasVision = (bool) (studio_api_key('qwen') ?: studio_api_key('dashscope'));
        $candidates = $hasVision ? max(1, min(3, (int) studio_config('swap_candidates', 2))) : 1;
        $urls = [];
        $calls = 0;
        foreach ($variants as $vi => $variant) {
            if (count($urls) >= $candidates) { break; }
            $url = $imageSvc->swapEdit($variant, $designImage, $swapModel, $inlineFace ? $faceRefUrl : null, $sendPoseImg ? $poseRefUrl : null);
            if ($url) {
                $urls[] = $url;
                $calls++;
                logger()->info('Swap PASS 1 candidate '.count($urls).' (variant '.($vi + 1).')');
            } else {
                logger()->warning('Swap PASS 1 variant '.($vi + 1).' failed, trying next');
            }
        }
        if (empty($urls)) {
            return null;
        }
        $this->calls = $calls;
        $this->lastModel = $imageSvc->lastModel() ?: $swapModel;

        $url = count($urls) > 1
            ? ($this->pickBestCandidate($urls, $designImage) ?? $urls[0])
            : $urls[0];

        // PASS 1b (NEW) — dedicated face-swap: replace ONLY the face with the reference image.
        // A separate pass prevents the edit model from ignoring the face reference (the original
        // evaluation found that sending a full-body ref alongside the garment image confuses the
        // model). With face-only as the sole instruction, fidelity is much higher.
        if ($changeFace && $faceRefUrl && ! $inlineFace) {
            $faceInstr = 'The FIRST image is the reference face; the SECOND image is the person to edit. Replace ONLY the face in the SECOND image with the face from the FIRST image. '
                .'Scale the reference face DOWN to match the natural head size of the person exactly — the face must NOT be enlarged or magnified beyond the original head; keep the face at the correct anatomical proportion of the head (about 1/7 of the body height, never larger). '
                .'If the reference face is angled, tilted or turned, match the head angle, direction and perspective of the SECOND image exactly — do NOT paste a frontal or mismatched-angle face onto a turned head. '
                .'If the person wears a hat, headband, scarf or other headwear, KEEP the hat, brim, headband and the hairline/hair EXACTLY as in the SECOND image — blend the new face naturally around the hat and bangs; do NOT remove, replace, resize or distort the headwear. '
                .'Keep the hairstyle, hairline, head shape, body, pose, background, and the EXACT garment (colors, prints, patterns, seams) 100% unchanged — do NOT redraw or restyle the outfit. '
                .'Match the skin tone and lighting of the reference face to the original body exactly; soften the face edges so the new face looks like a natural, fully-lit part of the head (not a pasted sticker). '
                .'Blend the new face seamlessly into the head — no visible seam, no color mismatch, no hard edge, natural jawline and cheek contour. '
                .$negativeClause
                .' Photorealistic, studio quality.';
            $withFace = $imageSvc->swapFace($faceInstr, $url, $swapModel, $faceRefUrl);
            if ($withFace) {
                $url = $withFace;
                $this->calls += 1;
                $this->lastModel = $imageSvc->lastModel() ?: $swapModel;
                logger()->info('Swap face-ref pass succeeded');
            } else {
                logger()->warning('Swap face-ref pass failed; keeping text-described face');
            }
        }

        // PASS 2 — replace ONLY the background (person / pose / garment untouched). CRITICAL: never
        // say "consistent lighting" here — with a dark background the model darkens the person into a
        // silhouette. Explicitly force the person to keep their original, fully-lit exposure.
        if ($wantBg) {
            $bgInstr = 'Replace the ENTIRE background of the scene with: '.$background.'. '
                .'Keep the person, their pose, the garment and body shape 100% unchanged. '
                .'Do NOT change the person brightness, exposure or lighting — the person must keep their original fully-lit look and stay clearly visible; do NOT darken or shade them into a silhouette to match the new background. '
                .'Frame the person at about 75-80% of the image height, with the background clearly visible all around them: pull the camera back a little and place the person slightly deeper into the scene so they are naturally scaled, not oversized or dominating the frame. '
                .'Blend the person into the scene: the HAIR and its edges, the clothing silhouette and the body outline must merge naturally with the background — NO hard cut-out outline, halo, white fringe or aliasing around the hair; soften the hair edge so it melts into the flowers, wall and light behind it. '
                .'Unify the color grading, warmth and lighting of the person and the new background so they blend into ONE cohesive photograph with no visible seam, halo or separation between the person and the scene. '
                .$toneS.' '.$negativeClause.' Photorealistic.';
            $final = $imageSvc->swapEdit($bgInstr, $url, $swapModel, null, null);
            if ($final) {
                $this->calls += 1;
                $this->lastModel = $imageSvc->lastModel() ?: $swapModel;
                logger()->info('Swap 2-pass done (person+pose, then background)');
                return $final;
            }
            logger()->warning('Swap background pass failed; keeping the person-swap result');
        }

        return $url;
    }

    /**
     * Chọn bản đẹp nhất trong các PASS 1 candidates bằng vision QA (so với ảnh thiết kế gốc).
     * Trả null nếu không score được → giữ bản đầu tiên.
     */
    protected function pickBestCandidate(array $urls, string $designImage): ?string
    {
        $best = null;
        $bestScore = null;
        foreach ($urls as $u) {
            $s = $this->scoreCandidate($u, $designImage);
            $score = $s ? $this->candidateScore($s) : null;
            if ($score !== null && ($bestScore === null || $score > $bestScore)) {
                $bestScore = $score;
                $best = $u;
            }
        }
        if ($best) {
            logger()->info('Swap best candidate selected', ['score' => round((float) $bestScore, 2)]);
        }
        return $best;
    }

    /**
     * Vision QA (qwen-vl) so sánh candidate với ảnh thiết kế gốc trên 4 tiêu chí.
     */
    protected function scoreCandidate(string $imageUrl, string $designImage): ?array
    {
        $key = studio_api_key('qwen') ?: studio_api_key('dashscope');
        if (! $key) { return null; }
        $base = dashscope_base_url($key).'/compatible-mode/v1/chat/completions';
        $designUri = studio_vision_image_data_uri($designImage, 1600) ?: studio_vision_image_url($designImage);
        $candUri = studio_vision_image_data_uri($imageUrl, 1600) ?: studio_vision_image_url($imageUrl);
        $instruction = 'You are a fashion photography evaluator. The FIRST image is the ORIGINAL design (its garment is the product and must be preserved). The SECOND image is a virtual try-on result. Rate the result 1-10 on:'
            .'\n1. garment_preservation: how identical is the garment in image 2 to image 1 (colors, patterns, silhouette, length)'
            .'\n2. face_quality: sharp, natural, photorealistic face'
            .'\n3. pose_accuracy: natural, correctly executed pose'
            .'\n4. overall_aesthetic: overall appeal, lighting, composition'
            .'\nReturn ONLY valid JSON: {"garment_preservation":N,"face_quality":N,"pose_accuracy":N,"overall_aesthetic":N}';

        foreach (studio_qwen_vision_models() as $model) {
            try {
                $resp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(45)
                    ->post($base, [
                        'model' => $model,
                        'messages' => [[
                            'role' => 'user',
                            'content' => [
                                ['type' => 'image_url', 'image_url' => ['url' => $designUri]],
                                ['type' => 'image_url', 'image_url' => ['url' => $candUri]],
                                ['type' => 'text', 'text' => $instruction],
                            ],
                        ]],
                        'temperature' => 0.1,
                    ]);
                if ($resp->successful()) {
                    $raw = trim((string) data_get($resp->json(), 'choices.0.message.content'));
                    if (preg_match('/\{[^}]+\}/s', $raw, $m)) {
                        $scores = json_decode($m[0], true);
                        if (is_array($scores) && isset($scores['garment_preservation'])) {
                            return $scores;
                        }
                    }
                }
                if ($resp->status() === 404 || str_contains(strtolower((string) $resp->body()), 'not found')
                    || $resp->status() === 429 || $resp->status() >= 500) {
                    if ($resp->status() === 429) { sleep(2); }
                    continue;
                }
            } catch (\Throwable $e) { /* next model */ }
        }
        return null;
    }

    protected function candidateScore(array $s): float
    {
        return ((float) ($s['garment_preservation'] ?? 5) * 0.5)
            + ((float) ($s['pose_accuracy'] ?? 5) * 0.3)
            + ((float) ($s['overall_aesthetic'] ?? 5) * 0.2);
    }

    /**
     * Body-proportion descriptor (fixed at the default build=6 — the UI slider was removed, the
     * clause stays because it was part of the confirmed-good prompt).
     */
    protected function buildEnglish(int $v): string
    {
        return match (true) {
            $v >= 9 => 'tall, slender runway-model build with a slim waist, long legs and an ~1:8 head-to-body ratio',
            $v >= 7 => 'tall, slim fashion-model build with a slim waist, long legs and an ~1:7.5 head-to-body ratio',
            $v >= 5 => 'slim fashion-model build with a slim waist and an ~1:7 head-to-body ratio',
            $v >= 3 => 'slightly shorter, curvy/athletic build with an ~1:6.5 head-to-body ratio',
            default => 'shorter, fuller curvy build with an ~1:6 head-to-body ratio',
        };
    }

    /**
     * Color-tone instruction for the "Tông màu" effect. "auto" picks a grade that matches the chosen
     * background (dark/moody -> dramatic, street/urban -> warm, beige/neutral studio -> warm, else none).
     */
    protected function toneInstruction(string $tone, string $background = ''): string
    {
        $tone = strtolower(trim($tone));
        if (in_array($tone, ['none', ''], true)) {
            return '';
        }
        if ($tone === 'auto') {
            $tone = $this->autoToneForBackground($background);
            if ($tone === 'none') { return ''; }
        }
        return match ($tone) {
            'warm' => ' Color-grade the whole image with a warm, golden studio light that suits the scene.',
            'cool' => ' Color-grade the whole image with a cool, clean, soft tone that suits the scene.',
            'film' => ' Apply a gentle vintage film color grade: soft warm highlights, muted mid-tones, fine grain — keep the exposure balanced so highlights are NOT blown out.',
            'cinematic' => ' Apply a cinematic movie color grade: warm golden highlights with teal shadows, smooth contrast and rich color — like a feature film frame.',
            'dramatic' => ' Apply a dramatic, moody high-contrast color grade with deeper shadows that suits the scene.',
            'mono' => ' Render the final image in elegant black-and-white monochrome with soft contrast.',
            default => '',
        };
    }

    protected function autoToneForBackground(string $background): string
    {
        $b = strtolower((string) $background);
        if ($b === '' || in_array($b, ['keep', 'original'], true)) { return 'none'; }
        if (str_contains($b, 'dark') || str_contains($b, 'moody') || str_contains($b, 'tối') || str_contains($b, 'đêm')) { return 'dramatic'; }
        if (str_contains($b, 'street') || str_contains($b, 'urban') || str_contains($b, 'đường') || str_contains($b, 'phố')) { return 'warm'; }
        if (str_contains($b, 'beige') || str_contains($b, 'neutral') || str_contains($b, 'warm') || str_contains($b, 'cream') || str_contains($b, 'seamless')) { return 'warm'; }
        if (str_contains($b, 'white') || str_contains($b, 'trắng')) { return 'cool'; }
        return 'none';
    }

}
