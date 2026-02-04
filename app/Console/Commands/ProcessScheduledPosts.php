<?php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessScheduledPosts extends Command
{
    protected $signature = 'social:process-scheduled-posts';

    protected $description = 'Process and publish scheduled social media posts';

    public function handle()
    {
        $posts = ScheduledPost::readyToPublish()
            ->with(['item', 'user'])
            ->get();

        $this->info("Found {$posts->count()} posts to process");

        foreach ($posts as $post) {
            $this->processPost($post);
        }

        return Command::SUCCESS;
    }

    private function processPost(ScheduledPost $post)
    {
        $post->update(['status' => ScheduledPost::STATUS_PROCESSING]);

        $platformPostIds = [];
        $errors = [];

        foreach ($post->platforms as $platform) {
            try {
                $result = $this->publishToPlatform($post, $platform);

                if ($result['success']) {
                    $platformPostIds[$platform] = $result['post_id'];
                } else {
                    $errors[$platform] = $result['error'];
                }
            } catch (\Throwable $e) {
                $errors[$platform] = $e->getMessage();
                Log::error("Error publishing to {$platform}: " . $e->getMessage());
            }
        }

        if (count($errors) === count($post->platforms)) {
            $post->markAsFailed(implode('; ', $errors));
        } else {
            $post->markAsPublished($platformPostIds);
        }
    }

    private function publishToPlatform(ScheduledPost $post, string $platform): array
    {
        $account = SocialAccount::where('user_id', $post->user_id)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return ['success' => false, 'error' => 'Account not connected'];
        }

        if ($account->isTokenExpired()) {
            return ['success' => false, 'error' => 'Token expired'];
        }

        $item = $post->item;

        switch ($platform) {
            case 'facebook':
                return $this->publishToFacebook($post, $account, $item);
            case 'instagram':
                return $this->publishToInstagram($post, $account, $item);
            default:
                return ['success' => false, 'error' => 'Unsupported platform'];
        }
    }

    private function publishToFacebook(ScheduledPost $post, SocialAccount $account, $item): array
    {
        $pageId = $account->page_id;
        $pageAccessToken = $account->page_access_token;

        if (!$pageId || !$pageAccessToken) {
            return ['success' => false, 'error' => 'Facebook Page not configured'];
        }

        $caption = $this->buildCaption($post, $item);
        $imageUrl = $item->image;

        try {
            if ($imageUrl) {
                $response = Http::post("https://graph.facebook.com/v18.0/{$pageId}/photos", [
                    'url' => $imageUrl,
                    'message' => $caption,
                    'access_token' => $pageAccessToken,
                ]);
            } else {
                $response = Http::post("https://graph.facebook.com/v18.0/{$pageId}/feed", [
                    'message' => $caption,
                    'access_token' => $pageAccessToken,
                ]);
            }

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'post_id' => $data['id'] ?? $data['post_id'] ?? null,
                ];
            }

            $error = $response->json()['error']['message'] ?? 'Unknown error';
            return ['success' => false, 'error' => $error];

        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function publishToInstagram(ScheduledPost $post, SocialAccount $account, $item): array
    {
        $igAccountId = $account->instagram_account_id;
        $accessToken = $account->access_token;

        if (!$igAccountId) {
            return ['success' => false, 'error' => 'Instagram account not linked'];
        }

        $caption = $this->buildCaption($post, $item);
        $imageUrl = $item->image;

        if (!$imageUrl) {
            return ['success' => false, 'error' => 'Instagram requires an image'];
        }

        try {
            $containerResponse = Http::post("https://graph.facebook.com/v18.0/{$igAccountId}/media", [
                'image_url' => $imageUrl,
                'caption' => $caption,
                'access_token' => $accessToken,
            ]);

            if (!$containerResponse->successful()) {
                $error = $containerResponse->json()['error']['message'] ?? 'Failed to create media container';
                return ['success' => false, 'error' => $error];
            }

            $containerId = $containerResponse->json()['id'];

            $publishResponse = Http::post("https://graph.facebook.com/v18.0/{$igAccountId}/media_publish", [
                'creation_id' => $containerId,
                'access_token' => $accessToken,
            ]);

            if ($publishResponse->successful()) {
                return [
                    'success' => true,
                    'post_id' => $publishResponse->json()['id'] ?? null,
                ];
            }

            $error = $publishResponse->json()['error']['message'] ?? 'Failed to publish';
            return ['success' => false, 'error' => $error];

        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function buildCaption(ScheduledPost $post, $item): string
    {
        $caption = $post->caption ?: $item->name;

        if ($item->price) {
            $caption .= "\n\n💰 Cijena: {$item->price} KM";
        }

        if ($item->description) {
            $caption .= "\n\n" . substr($item->description, 0, 200);
            if (strlen($item->description) > 200) {
                $caption .= '...';
            }
        }

        $itemUrl = config('app.frontend_url') . '/product-details/' . ($item->slug ?? $item->id);
        $caption .= "\n\n🔗 {$itemUrl}";

        if ($post->hashtags) {
            $caption .= "\n\n" . $post->hashtags;
        }

        return $caption;
    }
}
