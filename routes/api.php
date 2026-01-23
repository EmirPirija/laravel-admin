<?php
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ItemStatisticsController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\GamificationController;
use App\Http\Controllers\ItemQuestionController;
use App\Http\Controllers\Api\SellerSettingsController;
use App\Http\Controllers\ItemConversationController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('track/view', [ItemStatisticsController::class, 'trackView']);
Route::post('track/search-impressions', [ItemStatisticsController::class, 'trackBatchSearchImpressions']);
Route::post('track/search-click', [ItemStatisticsController::class, 'trackSearchClick']);
Route::get('share/{token}', [ItemStatisticsController::class, 'handleShareClick']);
Route::post('item-statistics/track-view', [ItemStatisticsController::class, 'trackView']);
Route::post('item-statistics/track-contact', [ItemStatisticsController::class, 'trackContact']);
Route::post('item-statistics/track-share', [ItemStatisticsController::class, 'trackShare']);
Route::post('item-statistics/track-engagement', [ItemStatisticsController::class, 'trackEngagement']);
Route::post('item-statistics/track-time', [ItemStatisticsController::class, 'trackTimeOnPage']);
Route::post('item-statistics/track-favorite', [ItemStatisticsController::class, 'trackFavorite']);
Route::post('item-statistics/track-search-impressions', [ItemStatisticsController::class, 'trackBatchSearchImpressions']);
Route::post('item-statistics/track-search-click', [ItemStatisticsController::class, 'trackSearchClick']);


/* Authenticated Routes */
Route::group(['middleware' => ['auth:sanctum']], static function () {
    Route::get('get-package', [ApiController::class, 'getPackage']);
    Route::post('update-profile', [ApiController::class, 'updateProfile']);
    Route::delete('delete-user', [ApiController::class, 'deleteUser']);
    Route::get('get-user-info', [ApiController::class, 'getUser']);

    Route::get('my-items', [ApiController::class, 'getItem']);
    Route::post('add-item', [ApiController::class, 'addItem']);
    Route::post('update-item', [ApiController::class, 'updateItem']);
    Route::post('delete-item', [ApiController::class, 'deleteItem']);
    Route::post('update-item-status', [ApiController::class, 'updateItemStatus']);
    Route::get('item-buyer-list', [ApiController::class, 'getItemBuyerList']);

    Route::post('renew-item', [ApiController::class, 'renewItem']);

    Route::post('item-sale', [SaleController::class, 'recordSale']);
    Route::post('item-reserve', [SaleController::class, 'handleReservation']);
    Route::get('my-purchases', [SaleController::class, 'myPurchases']);
    Route::get('my-sales', [SaleController::class, 'mySales']);

        // Pitanja
        Route::post('add-question', [ItemQuestionController::class, 'addQuestion']);
        Route::post('answer-question', [ItemQuestionController::class, 'answerQuestion']);
        Route::post('like-question', [ItemQuestionController::class, 'likeQuestion']);
        Route::post('delete-question', [ItemQuestionController::class, 'deleteQuestion']);
        Route::post('report-question', [ItemQuestionController::class, 'reportQuestion']);
    
        // Provjera konverzacije
        Route::get('check-item-conversation', [ItemConversationController::class, 'checkConversation']);
    
        // Seller Settings
        Route::get('get-seller-settings', [SellerSettingsController::class, 'getSettings']);
        Route::post('update-seller-settings', [SellerSettingsController::class, 'updateSettings']);

    // ============================================
    // MEMBERSHIP API 
    // ============================================
    Route::prefix('membership')->group(function () {
        Route::get('/user-membership', [MembershipController::class, 'getUserMembership']);
        Route::get('/tiers', [MembershipController::class, 'getMembershipTiers']);
        Route::post('/upgrade', [MembershipController::class, 'upgradeMembership']);
        Route::post('/cancel', [MembershipController::class, 'cancelMembership']);
    });

    Route::post('assign-free-package', [ApiController::class, 'assignFreePackage']);
    Route::post('make-item-featured', [ApiController::class, 'makeFeaturedItem']);
    Route::post('manage-favourite', [ApiController::class, 'manageFavourite']);
    Route::post('add-reports', [ApiController::class, 'addReports']);
    Route::get('get-notification-list', [ApiController::class, 'getNotificationList']);
    Route::get('get-limits', [ApiController::class, 'getLimits']);
    Route::get('get-favourite-item', [ApiController::class, 'getFavouriteItem']);

    Route::get('get-payment-settings', [ApiController::class, 'getPaymentSettings']);
    Route::post('payment-intent', [ApiController::class, 'getPaymentIntent']);
    Route::get('payment-transactions', [ApiController::class, 'getPaymentTransactions']);

    // In routes/api.php - Chat Module section
    Route::post('item-offer', [ApiController::class, 'createItemOffer']);
    Route::get('chat-list', [ApiController::class, 'getChatList']);
    Route::post('send-message', [ApiController::class, 'sendMessage']);
    Route::get('chat-messages', [ApiController::class, 'getChatMessages']);
    Route::post('/chat/typing', [ChatController::class, 'sendTypingIndicator']);
    Route::post('/chat/seen/{id}', [ChatController::class, 'markAsSeen']);
    Route::post('/chat/heartbeat', [ChatController::class, 'heartbeat']);
    Route::post('/chat/offline', [ChatController::class, 'setOffline']);
    Route::post('/chat/online-status', [ChatController::class, 'checkOnlineStatus']);
    Route::post('/chat/archive/{id}', [ChatController::class, 'archiveChat']);
    Route::post('/chat/unarchive/{id}', [ChatController::class, 'unarchiveChat']);
    Route::delete('/chat/{id}', [ChatController::class, 'deleteChat']);
    Route::post('/chat/mark-unread/{id}', [ChatController::class, 'markAsUnread']);
    Route::post('/chat/pin/{id}', [ChatController::class, 'pinChat']);
    Route::post('chat/mute/{id}', [ChatController::class, 'muteChat']);
    Route::post('chat/unmute/{id}', [ChatController::class, 'unmuteChat']);

    Route::get('item-statistics/{itemId}', [ItemStatisticsController::class, 'getStatistics']);
    Route::get('item-statistics/{itemId}/quick', [ItemStatisticsController::class, 'getQuickStats']);

    // ============================================
    // GAMIFICATION API 
    // ============================================
    Route::prefix('gamification')->group(function () {
        Route::get('/user-badges', [GamificationController::class, 'getUserBadges']);
        Route::get('/user-points', [GamificationController::class, 'getUserPoints']);
        Route::get('/leaderboard', [GamificationController::class, 'getLeaderboard']);
        Route::get('/badges', [GamificationController::class, 'getAllBadges']);
        Route::get('/points-history', [GamificationController::class, 'getPointsHistory']);
        Route::post('/admin/award-badge', [GamificationController::class, 'manuallyAwardBadge']);
    });

    Route::post('in-app-purchase', [ApiController::class, 'inAppPurchase']);

    Route::post('block-user', [ApiController::class, 'blockUser']);
    Route::post('unblock-user', [ApiController::class, 'unblockUser']);
    Route::get('blocked-users', [ApiController::class, 'getBlockedUsers']);

    Route::post('add-item-review', [ApiController::class, 'addItemReview']);
    Route::get('my-review', [ApiController::class, 'getMyReview']);
    Route::post('add-review-report', [ApiController::class, 'addReviewReport']);

    Route::get('verification-fields', [ApiController::class, 'getVerificationFields']);
    Route::post('send-verification-request',[ApiController::class,'sendVerificationRequest']);
    Route::get('verification-request',[ApiController::class,'getVerificationRequest']);
    Route::post('bank-transfer-update', [ApiController::class, 'bankTransferUpdate']);

    Route::post('job-apply', [ApiController::class, 'applyJob']);
    Route::get('get-job-applications', [ApiController::class, 'recruiterApplications']);
    Route::get('my-job-applications', [ApiController::class, 'myJobApplications']);
    Route::post('update-job-applications-status', [ApiController::class, 'updateJobStatus']);
    Route::post('logout', [ApiController::class, 'logout']);
});



/* Non Authenticated Routes */
Route::get('get-otp', [ApiController::class, 'getOtp']);
Route::get('verify-otp', [ApiController::class, 'verifyOtp']);
Route::get('get-package', [ApiController::class, 'getPackage']);
Route::get('get-languages', [ApiController::class, 'getLanguages']);
Route::post('user-signup', [ApiController::class, 'userSignup']);
Route::post('set-item-total-click', [ApiController::class, 'setItemTotalClick']);
Route::get('get-system-settings', [ApiController::class, 'getSystemSettings']);
Route::get('app-payment-status', [ApiController::class, 'appPaymentStatus']);
Route::get('get-customfields', [ApiController::class, 'getCustomFields']);
Route::get('get-item', [ApiController::class, 'getItem']);
Route::get('get-slider', [ApiController::class, 'getSlider']);
Route::get('get-report-reasons', [ApiController::class, 'getReportReasons']);
Route::get('get-categories', [ApiController::class, 'getSubCategories']);
Route::get('get-parent-categories', [ApiController::class, 'getParentCategoryTree']);
Route::get('get-featured-section', [ApiController::class, 'getFeaturedSection']);
Route::get('blogs', [ApiController::class, 'getBlog']);
Route::get('blog-tags', [ApiController::class, 'getAllBlogTags']);
Route::get('faq', [ApiController::class, 'getFaqs']);
Route::get('tips', [ApiController::class, 'getTips']);
Route::get('countries', [ApiController::class, 'getCountries']);
Route::get('states', [ApiController::class, 'getStates']);
Route::get('cities', [ApiController::class, 'getCities']);
Route::get('areas', [ApiController::class, 'getAreas']);
Route::post('contact-us', [ApiController::class, 'storeContactUs']);
Route::get('seo-settings', [ApiController::class, 'seoSettings']);
Route::get('get-seller', [ApiController::class, 'getSeller']);
Route::get('get-categories-demo', [ApiController::class, 'getCategories']);
Route::get('get-location', [ApiController::class, 'getLocationFromCoordinates']);
Route::get('get-item-slug', [ApiController::class, 'getItemSlugs']);
Route::get('get-categories-slug', [ApiController::class, 'getCategoriesSlug']);
Route::get('get-blogs-slug', [ApiController::class, 'getBlogsSlug']);
Route::get('get-featured-section-slug', [ApiController::class, 'getFeatureSectionSlug']);
Route::get('get-seller-slug', [ApiController::class, 'getSellerSlug']);

// ============================================
// JAVNA PITANJA NA OGLASIMA
// ============================================

// Dohvati pitanja (može i bez auth)
Route::get('item-questions', [ItemQuestionController::class, 'getQuestions']);