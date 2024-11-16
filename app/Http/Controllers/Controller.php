<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\ClassGroup;
use App\Models\ClassSchool;
use App\Models\Faq;
use App\Models\Feature;
use App\Models\FeatureSection;
use App\Models\Gallery;
use App\Models\Language;
use App\Models\Package;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\Slider;
use App\Models\Stream;
use App\Models\Students;
use App\Models\User;
use App\Repositories\Guidance\GuidanceInterface;
use App\Repositories\SystemSetting\SystemSettingInterface;
use App\Services\CachingService;
use App\Services\ResponseService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Controller extends BaseController {
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    private SystemSettingInterface $systemSettings;
    private GuidanceInterface $guidance;
    private SubscriptionService $subscriptionService;
    private CachingService $cache;

    public function __construct(SystemSettingInterface $systemSettings, GuidanceInterface $guidance, SubscriptionService $subscriptionService, CachingService $cache) {
        $this->systemSettings = $systemSettings;
        $this->guidance = $guidance;
        $this->subscriptionService = $subscriptionService;
        $this->cache = $cache;
    }

    public function index() {

        if (Auth::user()) {
            return redirect('dashboard');
        }

        // School website
        $fullDomain = $_SERVER['HTTP_HOST'];
        $parts = explode('.', $fullDomain);
        $subdomain = $parts[0];
        $school = School::where('domain',$subdomain)->first();

        if ($school) {
            // Get current subscription features
            $subscription = $this->subscriptionService->active_subscription($school->id);
            if ($subscription) {
                $features = $subscription->subscription_feature->pluck('feature.name')->toArray();
                $addons = $subscription->addons->pluck('feature.name')->toArray();
                $features = array_merge($features, $addons);
                // Check website management feature
                if (in_array('Website Management', $features)) {
                    return $this->school_website($school);    
                }    
            }
            
        }
        // End school website

        $features = Feature::activeFeatures()->get();
        $settings = app(CachingService::class)->getSystemSettings();
        $schoolSettings = SchoolSetting::where('name', 'horizontal_logo')->get();

        $about_us_lists = $settings['about_us_points'] ?? 'Affordable price, Easy to manage admin panel, Data Security';
        $about_us_lists = explode(",", $about_us_lists);
        $faqs = Faq::where('school_id', null)->get();
        $featureSections = FeatureSection::with('feature_section_list')->orderBy('rank', 'ASC')->get();
        $guidances = $this->guidance->builder()->get();
        $languages = Language::get();

        $school = School::count();

        try {
            $student = User::role('Student')->whereHas('school',function($q) {
                $q->whereNull('deleted_at')->where('status',1);
            })->count();
            $teacher = User::role('Teacher')->whereHas('school',function($q) {
                $q->whereNull('deleted_at')->where('status',1);
            })->count();
        } catch (Throwable) {
            // If role does not exist in fresh installation then set the counter to 0
            $student = 0;
            $teacher = 0;
        }


        $counter = [
            'school'  => $school,
            'student' => $student,
            'teacher' => $teacher,
        ];

        $packages = Package::where('status', 1)->with('package_feature.feature')->where('status', 1)->orderBy('rank', 'ASC')->get();

        $trail_package = $packages->where('is_trial', 1)->first();
        if ($trail_package) {
            $trail_package = $trail_package->id;
        }
        return view('home', compact('features', 'packages', 'settings', 'faqs', 'guidances', 'languages', 'schoolSettings', 'featureSections', 'about_us_lists', 'counter', 'trail_package'));
    }

    public function school_website($school)
    {

        $sliders = Slider::where('school_id',$school->id)->whereIn('type',[2,3])->get();
        if (!count($sliders)) {
            $sliders = [
                url('assets/school/images/heroImg1.jpg'),
                url('assets/school/images/heroImg2.jpg'),
            ];
        }
        $faqs = Faq::where('school_id',$school->id)->get();

        $students = Students::where('school_id', $school->id)->whereHas('user', function($q) {
            $q->where('status',1);
        })->count();

        $classes = ClassSchool::where('school_id', $school->id)->count();
        $streams = Stream::where('school_id', $school->id)->count();

        $counters = [
            'students' => $students,
            'classes' => $classes,
            'streams' => $streams,
        ];

        $announcements = Announcement::where('school_id',$school->id)->whereHas('announcement_class',function($q) {
            $q->where('class_subject_id',null);
        })->with('announcement_class.class_section.class.stream','announcement_class.class_section.section','announcement_class.class_section.medium')->orderBy('id','DESC')->take(10)->get();

        $class_groups = ClassGroup::where('school_id',$school->id)->get();

        return view('school-website.index',compact('sliders','faqs','counters','announcements','class_groups'));
    }

    public function contact(Request $request) {
        try {
            $admin_email = app(CachingService::class)->getSystemSettings('mail_username');
            $data = [
                'name'        => $request->name,
                'email'       => $request->email,
                'description' => $request->message,
                'admin_email' => $admin_email
            ];

            Mail::send('contact', $data, static function ($message) use ($data) {
                $message->to($data['admin_email'])->subject('Get In Touch');
            });

            return redirect()->to('/#contact')->with('success', "Message send successfully");
        } catch (Throwable) {
            return redirect()->to('/#contact')->with('error', "Apologies for the Inconvenience: Please Try Again Later");
        }


    }

    public function cron_job() {
        Artisan::call('schedule:run');
    }

    public function relatedDataIndex($table, $id) {
        $databaseName = config('database.connections.mysql.database');

        //Fetch all the tables in which current table's id used as foreign key
        $relatedTables = DB::select("SELECT TABLE_NAME,COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_NAME = ? AND TABLE_SCHEMA = ?", [$table, $databaseName]);
        $data = [];
        foreach ($relatedTables as $relatedTable) {
            $q = DB::table($relatedTable->TABLE_NAME)->where($relatedTable->TABLE_NAME . "." . $relatedTable->COLUMN_NAME, $id);
            $data[$relatedTable->TABLE_NAME] = $this->buildRelatedJoinStatement($q, $relatedTable->TABLE_NAME)->get()->toArray();
        }

        $currentDataQuery = DB::table($table);

        $currentData = $this->buildRelatedJoinStatement($currentDataQuery, $table)->first();
        return view('related-data.index', compact('data', 'currentData', 'table'));
    }

    private function buildSelectStatement($query, $table) {
        $select = [
            "classes"        => "classes.*,CONCAT(classes.name,'(',mediums.name,')') as name,streams.name as stream_name,shifts.name as shift_name",
            "class_sections" => "class_sections.*,CONCAT(classes.name,' ',sections.name,'(',mediums.name,')') as class_section",
            "users"          => "users.first_name,users.last_name",
            //            "student_subjects" => "student_subjects.*,CONCAT(users.first_name,' ',users.last_name) as student,"
        ];
        return $query->select(DB::raw($select[$table] ?? "*," . $table . ".id as id"));
    }


    private function buildRelatedJoinStatement($query, $table) {
        $databaseName = config('database.connections.mysql.database');
        // If all the child tables further have foreign keys than fetch that table also
        $getTableSchema = DB::select("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$table, $databaseName]);

        $tableAlias = [];
        //Build Join query for all the foreign key using the Table Schema
        foreach ($getTableSchema as $foreignKey) {
//            , 'edited_by', 'created_by', 'guardian_id'
            if ($foreignKey->REFERENCED_TABLE_NAME == $table) {
                //If Related table has foreign key of the same table then no need to add that in join to reduce the query load
                continue;
            }

            // Sometimes there will be same table is used in multiple foreign key at that time alias of the table should be different
            if (in_array($foreignKey->REFERENCED_TABLE_NAME, $tableAlias)) {
                $count = array_count_values($tableAlias)[$foreignKey->REFERENCED_TABLE_NAME] + 1;
                $currentAlias = $foreignKey->REFERENCED_TABLE_NAME . $count;
            } else {
                $currentAlias = $foreignKey->REFERENCED_TABLE_NAME;
            }
            $tableAlias[] = $foreignKey->REFERENCED_TABLE_NAME;

            if (!in_array($foreignKey->COLUMN_NAME, ['school_id', 'session_year_id'])) {
                $query->leftJoin($foreignKey->REFERENCED_TABLE_NAME . " as " . $currentAlias, $foreignKey->REFERENCED_TABLE_NAME . "." . $foreignKey->REFERENCED_COLUMN_NAME, '=', $table . "." . $foreignKey->COLUMN_NAME);
            }
        }

        return $this->buildSelectStatement($query, $table);
    }

    public function relatedDataDestroy($table, $id) {
        try {
            DB::table($table)->where('id', $id)->delete();
            ResponseService::successResponse("Data Deleted Permanently");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Controller -> relatedDataDestroy Method", 'cannot_delete_because_data_is_associated_with_other_data');
            ResponseService::errorResponse();
        }

    }

    public function about_us()
    {
        return view('school-website.about_us');
    }

    public function contact_us()
    {
        return view('school-website.contact');
    }

    public function contact_form(Request $request) {


        try {
            $admin_email = app(CachingService::class)->getSystemSettings('mail_username');
            $data = [
                'name'        => $request->name,
                'email'       => $request->email,
                'subject'     => $request->subject,
                'description' => $request->message,
                'admin_email' => $admin_email,
                'school_email' => $request->school_email
            ];

            Mail::send('contact', $data, static function ($message) use ($data) {
                $message->to($data['school_email'])->subject($data['subject']);
            });

            return redirect()->to('school/contact-us')->with('success', "Message send successfully");
        } catch (Throwable) {
            return redirect()->to('school/contact-us')->with('error', "Apologies for the Inconvenience: Please Try Again Later");
        }
    }

    public function photo()
    {
        return view('school-website.photo');
    }

    public function photo_file($id)
    {
        try {
            $photos = Gallery::with(['file' => function($q) {
                $q->where('type',1);
            }])->find($id);
            if ($photos) {
                return view('school-website.photo_file',compact('photos'));
            } else {
                return redirect('school/photos');
            }
        } catch (\Throwable $th) {
            return redirect('school/photos');
        }
    }

    public function video()
    {
        return view('school-website.video');
    }

    public function video_file($id)
    {
        try {
            $videos = Gallery::with(['file' => function($q) {
                $q->where('type',2);
            }])->find($id);
            if ($videos) {
                return view('school-website.video_file',compact('videos'));
            } else {
                return redirect('school/videos');
            }
        } catch (\Throwable $th) {
            return redirect('school/videos');
        }
    }

    public function terms_conditions()
    {
        return view('school-website.terms_conditions');
    }

    public function privacy_policy()
    {
        return view('school-website.privacy_policy');
    }
   

    
}
