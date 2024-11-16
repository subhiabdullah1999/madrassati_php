<?php

namespace App\Imports;

use App\Repositories\User\UserInterface;
use App\Services\CachingService;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use App\Repositories\Subscription\SubscriptionInterface;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Staff\StaffInterface;
use App\Services\SubscriptionService;
use App\Services\UserService;
use Str;
use Throwable;
use TypeError;

class TeacherImport implements ToCollection, WithHeadingRow
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        // dd($collection);
        $subscription = app(SubscriptionInterface::class);
        $user = app(UserInterface::class);
        $cache = app(CachingService::class);
        $staff = app(StaffInterface::class);

        $validator = Validator::make($collection->toArray(), [
            '*.first_name'        => 'required',
            '*.last_name'         => 'required',
            '*.gender'            => 'required',
            '*.email'             => 'required|email',
            '*.mobile'            => 'required|numeric|digits_between:1,16',
            '*.dob'               => 'required|date',
            '*.qualification'     => 'required',
            '*.current_address'   => 'required',
            '*.permanent_address' => 'required',
            '*.salary' => 'required',
        ],[
            '*.dob.date' => 'Please ensure that the dob date format you use is either DD-MM-YYYY or MM/DD/YYYY.'
        ]);

        $validator->validate();

        // Check free trial package
        $today_date = Carbon::now()->format('Y-m-d');
        $subscription = $subscription->builder()->doesntHave('subscription_bill')->whereDate('start_date','<=',$today_date)->where('end_date','>=',$today_date)->whereHas('package',function($q){
            $q->where('is_trial',1);
        })->first();
        
        if ($subscription) {
            $systemSettings = $cache->getSystemSettings();
            $staff = $user->builder()->role('Teacher')->withTrashed()->orWhereHas('roles', function ($q) {
                $q->where('custom_role', 1)->whereNotIn('name', ['Teacher','Guardian']);
            })->whereNotNull('school_id')->Owner()->count();
            if ($staff >= $systemSettings['staff_limit']) {
                $message = "The free trial allows only ".$systemSettings['staff_limit']." staff.";
                ResponseService::errorResponse($message);
            }
        }

        DB::beginTransaction();
        foreach($collection as $row)
        {
            try {
                $teacher_plain_text_password = str_replace('-', '', date('d-m-Y', strtotime($row['dob'])));
                
                $existingUser = $user->builder()->where('email', $row['email'])->first();
                
                $id = $existingUser ? $existingUser->id : null;
                $users = $user->updateOrCreate( ['id' => $id] ,[
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'mobile' => $row['mobile'],
                    'email' => $row['email'],
                    'dob' => $row['dob'],
                    'password'   => Hash::make( $teacher_plain_text_password),
                    'gender' => $row['gender'],
                    'current_address' => $row['current_address'],
                    'permanent_address' => $row['permanent_address'],
                    'status'     => 0,
                    'deleted_at' => '1970-01-01 01:00:00'
                ]);

                
                $users->assignRole('Teacher');

                $staff->create([
                    'user_id'       => $users->id,
                    'qualification' => $row['qualification'],
                    'salary'        => $row['salary']
                ]);

                $sendEmail = app(UserService::class);
                $sendEmail->sendStaffRegistrationEmail($users, $teacher_plain_text_password);

            } catch (Throwable $e) {
                // IF Exception is TypeError and message contains Mail keywords then email is not sent successfully
                if (Str::contains($e->getMessage(), ['Failed', 'Mail', 'Mailer', 'MailManager'])) {
                    continue;
                }
                DB::rollBack();
                throw $e;
            }
        }
        DB::commit();
        return true;
    }
}
