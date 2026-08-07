<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\CustomApprovers;
use App\Models\ApprovalProcess;
use Illuminate\Http\Request;
use App\Models\RequestForm;
use App\Models\User;
use App\Models\Attachment;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Notifications\ApprovalProcessNotification;
use App\Events\NotificationEvent;
use App\Models\ApproverChecker;
use App\Models\AVPFinanceStaff;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RequestFormController extends Controller
{
    private function handleAvpFinanceApproval($userId, &$approvalProcesses, &$level, $requestFormData, $branchId, $request)
    {
        // if (!$requestFormData) {
        //     Log::error("Invalid RequestForm data passed to handleAvpFinanceApproval.");
        //     return;
        // }
        // Check if the user is an AVPFinance
        $user = DB::table('users')->where('id', $userId)->first();

        // $approverChecker = ApproverChecker::query()
        //     ->where('checker_category', $request->kind_of_request)
        //     ->first();

        if ($user && $user->position === 'AVP - Finance') {
            $avpFinanceRecords = DB::table('a_v_p_finance_staff')->where('user_id', $userId)->get();

            // if ($approverChecker?->exists()) {

            //     if ($approverChecker?->checker_id) {
            //         $approvalProcesses[] = [
            //             'user_id' => $approverChecker?->checker_id,
            //             'request_form_id' => $requestFormData->id,
            //             'level' => $level,
            //             'status' => 'Pending',
            //             'created_at' => now(),
            //             'updated_at' => now(),
            //         ];
            //         $level++;
            //     }
            //     $approvalProcesses[] = [
            //         'user_id' => $approverChecker->user_id,
            //         'request_form_id' => $requestFormData->id,
            //         'level' => $level,
            //         'status' => 'Pending',
            //         'created_at' => now(),
            //         'updated_at' => now(),
            //     ];
            //     $level++;

            //     if (!in_array($approverChecker->user_id, $requestFormData->approved_by) && !in_array($approverChecker->user_id, $requestFormData?->noted_by ?? [])) {
            //         $requestFormData->update(['approved_by' => array_merge([$approverChecker->user_id], $requestFormData->approved_by)]);
            //     }
            // } else
            if ($avpFinanceRecords->isNotEmpty()) {
                $avpStaffs = $avpFinanceRecords->pluck('staff_id');


                $staff = AVPFinanceStaff::query()
                    ->whereIn('staff_id', $avpStaffs)
                    ->whereJsonContains('branch_id', (int) $branchId)
                    ->first();

                if ($staff) {
                    $approvalProcesses[] = [
                        'user_id' => $staff?->staff_id,
                        'request_form_id' => $requestFormData->id,
                        'level' => $level,
                        'status' => 'Pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $level++;
                }
            }
        }
        //  else {
        //     $approverCheckerUserId = $approverChecker?->user_id === $userId;

        //     if ($approverChecker?->checker_id && $approverCheckerUserId) {
        //         $approvalProcesses[] = [
        //             'user_id' => $approverChecker?->checker_id,
        //             'request_form_id' => $requestFormData->id,
        //             'level' => $level,
        //             'status' => 'Pending',
        //             'created_at' => now(),
        //             'updated_at' => now(),
        //         ];
        //         $level++;
        //     }
        // }

        // Add the AVPFinance user to the approval process

        $approvalProcesses[] = [
            'user_id' => $userId,
            'request_form_id' => $requestFormData->id,
            'level' => $level,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $level++;
    }


    //CREATE REQUEST
    public function createRequest(Request $request)
    {
        $formDataDecoded = json_decode($request->form_data);
        // Validate request data
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'form_type' => 'required|string',
            'form_data' => 'required|string', // Temporarily string for decoding
            'noted_by' => 'string',
            'currency' => 'required_if:form_type,Cash Disbursement Requisition Slip|string|in:PHP,USD,EUR',
            'approved_by' => 'required|string',
            'attachment.*' => ['file', 'max:3072'],
        ]);

        try {

            $userID = $validated['user_id'];
            $formType = $validated['form_type'];
            $formDataArray = json_decode($validated['form_data'], true);
            $notedByIds = !empty(json_decode($validated['noted_by'], true)) ? json_decode($validated['noted_by'], true) : null;
            $approvedByIds = json_decode($validated['approved_by'], true);
            $currency = $request->currency;

            /*    $formDataArray = $validated['form_data'];
               $notedByIds = $validated['noted_by'];
               $approvedByIds = $validated['approved_by']; */

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'message' => 'Invalid JSON format in input data',
                ], 400);
            }

            $user = DB::table('users')->where('id', $userID)->first();
            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                ], 404);
            }

            $branchCode = $user->branch_code;


            // Define form-specific validation rules
            $validationRules = [
                'Application For Cash Advance' => [
                    "date" => 'required|date',
                    "department" => 'required',
                    "reason" => 'required',
                    "amount" => 'required|numeric',
                    "liquidation_date" => 'required|date',
                    "usage" => 'required',
                    "items" => [
                        "date" => 'required|date',
                        'day' => 'required',
                        'from' => 'required',
                        'to' => 'required',
                        'activity' => 'required',
                        'hotel' => 'required',
                        'rate' => 'required|numeric',
                        'per_diem' => 'required',
                        'remarks' => 'required',
                    ],
                    'boat_fare' => 'required|numeric',
                    'hotel_amount' => 'required|numeric',
                    'per_diem_amount' => 'required|numeric',
                    'fare' => 'required|numeric',
                    'contingency' => 'required',
                ],
                'Cash Disbursement Requisition Slip' => [
                    'date' => 'required|date',
                    //'branch' => 'required',
                    'items' => [
                        'quantity' => 'required|numeric',
                        'description' => 'required',
                        'unit_cost' => 'required|numeric',
                        'total_amount' => 'required|numeric',
                        'usage' => 'required',
                    ]
                ],
                'Liquidation of Actual Expense' => [
                    'date' => 'required|date',
                    'purpose' => 'required',
                    'items' => [
                        'date' => 'required|date',
                        'from' => 'required',
                        'to' => 'required',
                        'transportation_type' => 'required',
                        'amount' => 'required|numeric',
                        'hotel_name' => 'required',
                        'place' => 'required',
                        'per_diem' => 'required',
                        'particulars' => 'required',
                    ]
                ],
                'Purchase Order Requisition Slip' => [
                    'date' => 'required|date',
                    //'branch' => 'required',
                    'supplier' => 'required',
                    'address' => 'required',
                    "items" => [
                        'quantity' => 'required|numeric',
                        'description' => 'required',
                        'unit_cost' => 'required|numeric',
                        'total_amount' => 'required|numeric',
                        'usage' => 'required',
                    ]
                ],
                'Refund Request' => [
                    //'branch' => 'required',
                    'date' => 'required|date',
                    "items" => [
                        'quantity' => 'required|numeric',
                        'description' => 'required',
                        'unit_cost' => 'required|numeric',
                        'total_amount' => 'required|numeric',
                        'usage' => 'required',
                    ]
                ],
                'Stock Requisition Slip' => [
                    'purpose' => 'required',
                    //'branch' => 'required',
                    'date' => 'required|date',
                    "items" => [
                        'quantity' => 'required|numeric',
                        'description' => 'required',
                        'unit_cost' => 'required|numeric',
                        'total_amount' => 'required|numeric',
                        'usage' => 'required',
                    ]
                ],
                'Discount Requisition Form' => [
                    'employement_status' => 'required',
                    //'branch' => 'required',
                    'date' => 'required|date',

                    "items" => [
                        'brand' => 'required|numeric',
                        'model' => 'required',
                        'unit' => 'required',
                        'partno' => 'required|numeric',
                        'labor' => 'required',
                        'spotcash' => 'required',
                        'discountedPrice' => 'required',
                    ],
                    'total_labor' => 'required|numeric',
                    'total_spotcash' => 'required|numeric',
                    'total_discount' => 'required|numeric',
                ],
                'Check Issuance Requisition Slip' => [
                    'payee' => 'required',
                    'bank' => 'required',
                    'account_no' => 'required',
                    'swift_code' => 'required',

                    "items" => [
                        'quantity' => 'required|numeric',
                        'description' => 'required',
                        'unit_cost' => 'required|numeric',
                        'total_amount' => 'required|numeric',
                        'usage' => 'required',
                    ]
                ],
            ];

            if (!isset($validationRules[$formType])) {
                return response()->json([
                    'message' => 'Invalid form type',
                ], 400);
            }

            // Begin transaction
            DB::beginTransaction();

            if ($request->form_type === 'Purchase Order Requisition Slip') {
                Supplier::updateOrCreate([
                    'name'      => $formDataDecoded[0]->supplier
                ], [
                    'address'   => $formDataDecoded[0]->address,
                ]);
            }

            if ($request->form_type === 'Check Issuance Requisition Slip') {
                Bank::firstOrCreate([
                    'name' => $formDataDecoded[0]->bank
                ]);
            }

            // Validate form data against rules
            $encodedFormData = [];
            foreach ($formDataArray as $key => $formData) {
                if ($key !== 'items') {
                    $encodedFormData[$key] = $formData;
                    continue;
                }

                $items = [];
                foreach ($formData as $item) {
                    $validator = Validator::make($item, $validationRules[$formType]['items']);
                    if ($validator->fails()) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => $validator->errors(),
                        ], 400);
                    }
                    $items[] = $item;
                }

                $encodedFormData['items'] = $items;
            }


            // Handle file uploads
            $filePaths = [];
            if ($request->hasFile('attachment')) {
                $files = is_array($request->file('attachment')) ? $request->file('attachment') : [$request->file('attachment')];

                foreach ($files as $file) {
                    // change to d_drive if using trunas
                    $file_name = Str::beforeLast($file->getClientOriginalName(), '.') . '-' . time() . '.' . $file->getClientOriginalExtension();
                    $filePath = $file->storeAs('request_form_attachments', $file_name, config('app.storage_disk'));
                    if (!$filePath) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'File upload failed',
                        ], 500);
                    }
                    $filePaths[] = $filePath;
                }
            }

            $uniqueCode = $this->generateUniqueCode($formType, $branchCode);

            // Create the request form
            $requestFormData = RequestForm::create([
                'user_id' => $userID,
                'form_type' => $formType,
                'form_data' => $encodedFormData, // Ensure it's JSON encoded,
                'currency' => $currency,
                'noted_by' => $notedByIds,
                'approved_by' => $approvedByIds, // Ensure it's JSON encoded
                'attachment' => json_encode($filePaths), // Ensure it's JSON encoded
                'request_code' => $uniqueCode,
                'branch_code' => $branchCode,
                'kind_of_request' => $request->kind_of_request
            ]);

            // Helper function to handle AVPFinance users and their staff

            // Initialize approval process variables
            $level = 1;
            $approvalProcesses = [];

            // Combine noted_by and approved_by into one array with labels
            if ($notedByIds !== null) {
                $approvers = [
                    ['type' => 'noted_by', 'ids' => $notedByIds],
                    ['type' => 'approved_by', 'ids' => $approvedByIds]
                ];
            } else {
                $approvers = [
                    ['type' => 'approved_by', 'ids' => $approvedByIds]
                ];
            }

            // Process each approver (noted_by and approved_by)
            foreach ($approvers as $approverGroup) {
                foreach ($approverGroup['ids'] as $approverId) {
                    // Use the helper function to handle AVPFinance and their staff
                    $this->handleAvpFinanceApproval($approverId, $approvalProcesses, $level, $requestFormData, $branchCode, $request);
                }
            }

            $items = collect($approvalProcesses)
                ->reverse()
                ->unique('user_id')
                ->reverse()
                ->values()
                ->map(
                    fn($item, $index)
                    =>
                    array_merge($item, ['level' => $index + 1])
                )
                ->all();

            // Insert all approval processes into the database
            ApprovalProcess::insert($items);

            // Notify the first approver (user 3 in this case)
            $firstApproverId = $notedByIds !== null ? $notedByIds[0] : $approvedByIds[0];
            $firstApprover = User::with('approverStaffs')->find($firstApproverId);

            if ($firstApprover) {
                $firstApprovalProcess = ApprovalProcess::where('request_form_id', $requestFormData->id)
                    ->where('level', 1)
                    ->first();

                $staff = User::where('id', $firstApprovalProcess->user_id)->first();
                if ($staff) {
                    $staff->notify(new ApprovalProcessNotification(
                        $firstApprovalProcess,
                        $staff->firstName,
                        $requestFormData,
                        $user->firstName,
                        $user->lastName
                    ));
                } else {
                    $firstApprover->notify(new ApprovalProcessNotification(
                        $firstApprovalProcess,
                        $firstApprovalProcess->firstName,
                        $requestFormData,
                        $user->firstName,
                        $user->lastName
                    ));
                }



                // Broadcast notification event
                $message = 'You have a request form to approve';
                $date = now();
                $type = 'App\Notifications\ApprovalProcessNotification';
                $read_at = null;
                //  event(new NotificationEvent($firstApprover->id, $message, $date,$type,$read_at));
            }

            // Commit transaction
            DB::commit();
            return response()->json([
                'message' => 'Request form created successfully',
                'data' => $requestFormData
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Request form creation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function generateUniqueCode($formType, $branchId)
    {
        $prefixes = [
            'Application For Cash Advance' => 'CA',
            'Cash Disbursement Requisition Slip' => 'CD',
            'Liquidation of Actual Expense' => 'LAE',
            'Purchase Order Requisition Slip' => 'PO',
            'Refund Request' => 'RR',
            'Stock Requisition Slip' => 'SRL',
            'Discount Requisition Form' => 'DRF',
            'Check Issuance Requisition Slip' => 'CIRS',
        ];

        if (isset($prefixes[$formType]) && $branchId) {
            // Find the latest request for the specific form type and branch, ordered by the code
            $lastRequest = RequestForm::where('form_type', $formType)
                ->where('branch_code', $branchId)
                ->orderBy('request_code', 'desc') // Assumes unique_code is stored in the DB
                ->first();

            // Determine the next number
            if ($lastRequest) {
                // Extract the numeric part of the code (after the prefix)
                $lastNumber = (int) substr($lastRequest->request_code, strlen($prefixes[$formType]));
                $nextNumber = $lastNumber + 1;
            } else {
                // If no previous record, start from 1
                $nextNumber = 1;
            }

            // Pad the next number with zeros to ensure it is 7 digits long
            $nextNumberPadded = str_pad($nextNumber, 7, '0', STR_PAD_LEFT);

            // Return the prefix along with the incremented number
            return $prefixes[$formType] . $nextNumberPadded;
        }

        return null;
    }
    public function updateRequest(Request $request, $id)
    {
        $request->validate([
            'attachment.*' => ['file', 'max:3072'],
        ]);

        DB::transaction(function () use ($request, $id) {
            $request_data = RequestForm::with('approvalProcess')->findOrFail($id);
            // Decode JSON strings
            $form_data_content = json_decode($request->input('form_data'), true);
            //$noted_by = !empty(json_decode($request->input('noted_by'), true)) ? json_decode($request->input('noted_by'), true) : null;
            $noted_by = json_decode($request->input('noted_by'), true);
            $approved_by = json_decode($request->input('approved_by'), true);
            $currency = $request->input('currency');
            // Initialize attachment paths
            $existing_attachments = json_decode($request_data->attachment, associative: true) ?? [];

            $user = Auth::user();

            // Process new attachments (upload)
            $attachment_paths = $existing_attachments; // Start with existing attachments

            if ($request->hasFile('new_attachments')) {
                foreach ($request->file('new_attachments') as $file) {
                    $file_name = Str::beforeLast($file->getClientOriginalName(), '.') . '-' . time() . '.' . $file->getClientOriginalExtension();
                    $attachment_paths[] = $file->storeAs('request_form_attachments', $file_name, config('app.storage_disk')); // Add new file paths
                }
            }

            // Add existing attachment paths (from input)
            foreach ($request->input('attachment_urls', []) as $value) {
                // Ensure the value is valid and not already in the list
                if ($value && !in_array('request_form_attachments/' . $value, $attachment_paths)) {
                    $attachment_paths[] = 'request_form_attachments/' . $value; // Add to list
                }
            }

            // Process removed attachments
            $removed_attachments = $request->input('removed_attachments', []);
            foreach ($removed_attachments as $path) {
                // Delete the file from storage
                if (Storage::disk(config('app.storage_disk'))->exists('request_form_attachments/' . $path)) {
                    Storage::disk(config('app.storage_disk'))->delete('request_form_attachments/' . $path);
                }
                // Remove from the attachment list as well
                $attachment_paths = array_filter($attachment_paths, function ($existing_path) use ($path) {
                    return basename($existing_path) !== basename($path); // Remove the file from the list
                });
            }

            // Update the request form data including attachment
            $request_data->update([
                'form_data' => $form_data_content,
                'noted_by' => $noted_by,
                'approved_by' => $approved_by,
                'attachment' => json_encode(array_values($attachment_paths)),
                'status' => "Pending",
                'currency' => $currency,
                'kind_of_request' => $request->kind_of_request
            ]);

            // Delete existing approval processes
            $request_data->approvalProcess()->delete();

            // Initialize approval process variables
            $level = 1;
            $approvalProcesses = [];

            // Build approvers list properly
            $approvers = [];

            if (!empty($noted_by)) {
                $approvers[] = ['type' => 'noted_by', 'ids' => $noted_by];
            }

            if (!empty($approved_by)) {
                $approvers[] = ['type' => 'approved_by', 'ids' => $approved_by];
            }

            foreach ($approvers as $approverGroup) {
                foreach ($approverGroup['ids'] as $approverId) {
                    // Use the helper function to handle AVPFinance and their staff
                    $this->handleAvpFinanceApproval($approverId, $approvalProcesses, $level, $request_data, $user->branch_code, $request);
                }
            }

            // Insert all approval processes into the database
            ApprovalProcess::insert($approvalProcesses);

            // Notify first approver
            $firstApprover = !empty($noted_by) ? $noted_by[0] : $approved_by[0];
            if ($firstApprover) {
                $firstApproverUser = User::with('approverStaffs')->find($firstApprover);

                if ($firstApproverUser) {
                    $firstApprovalProcess = ApprovalProcess::where('request_form_id', $request_data->id)
                        ->where('level', 1)
                        ->first();

                    $requester = User::find($request_data->user_id);
                    $requesterFirstName = $requester->firstName ?? 'N/A';
                    $requesterLastName = $requester->lastName ?? 'N/A';

                    $firstApproverUser->notify(new ApprovalProcessNotification(
                        $firstApprovalProcess,
                        $firstApproverUser->firstName,
                        $request_data,
                        $requesterFirstName,
                        $requesterLastName
                    ));

                    // Broadcast notification count update
                    $message = 'You have a request form to approve';
                    $date = now();
                    $type = 'App\Notifications\ApprovalProcessNotification';
                    $read_at = null;
                    // event(new NotificationEvent(Auth::user()->id, $firstApprovalProcess->user->id));
                }
            }

            return response()->json(['message' => 'Request form updated successfully'], 200);
        });
    }

    public function uploadAttachments(Request $request, $requestFormId)
    {

        $requestForm = RequestForm::findOrFail($requestFormId);
        $attachmentIds = [];

        if ($request->hasFile('attachment')) {
            foreach ($request->file('attachment') as $file) {
                $filePath = $file->store('request_form_attachments', config('app.storage_disk'));
                $fileName = $file->getClientOriginalName();

                if (!$filePath) {
                    return response()->json([
                        'message' => 'File upload failed',
                    ], 500);
                }

                $attachment = Attachment::create([
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'request_form_id' => $requestFormId,
                ]);

                $attachmentIds[] = $attachment->id;
            }
        }

        if (!empty($attachmentIds)) {
            $requestForm->update([
                'attachment' => $attachmentIds,
            ]);
        }

        return response()->json([
            'message' => "Attachment uploaded successfully"
        ]);
    }
    //VIEW REQUEST
    public function viewRequest($id)
    {
        try {

            $requestForm = RequestForm::findOrFail($id);

            return response()->json([
                'message' => 'Request form retrieved successfully',
                'data' => $requestForm
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Request form not found',
            ], 404);
        } catch (\Exception $e) {

            return response()->json([
                'message' => 'An error occurred while retrieving the request form',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //VIEW REQUEST FORM CREATED BY SPECIFIC USER


    public function index()
    {
        $perPage = request("per_page") ?: 10;
        $search = request("search") ?: "";
        $status_req = request('status', '');
        function paginateCollection($items, $perPage, $currentPage = null, $options = [])
        {
            $currentPage = $currentPage ?: LengthAwarePaginator::resolveCurrentPage();
            $items = $items instanceof Collection ? $items : Collection::make($items);
            $pagedItems = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

            return new LengthAwarePaginator(
                $pagedItems,
                $items->count(),
                $perPage,
                $currentPage,
                $options
            );
        }
        try {
            $currentUserId = Auth::user()->id;
            $current_branch = Auth::user()->branch_code;

            $dateSearch = "";

            if (Carbon::hasFormat($search, 'F j, Y')) {

                $dateSearch = Carbon::parse($search)->format("Y-m-d");
            }

            // Fetch request forms where user_id matches the current user's ID
            $requestForms = RequestForm::with(['user', 'user.branch', 'approvalProcess', 'branchCode'])
                ->where('user_id', $currentUserId)
                ->when(
                    $search,
                    fn($query) =>
                    $query->where(
                        fn($q) =>
                        $q->where('form_type', 'LIKE', "%{$search}%")
                            ->orWhere('request_code', 'LIKE', "%{$search}%")
                            ->orWhereDate('created_at', $dateSearch)
                            ->orWhere('kind_of_request', 'LIKE', "%{$search}%")
                    )
                )
                ->when(
                    $status_req !== "ALL",
                    fn($query)

                    =>
                    $query->where('status', $status_req)
                )
                ->select('id', 'user_id', 'form_type', 'form_data', 'cancelation_reason', 'status', 'currency', 'noted_by', 'approved_by', 'attachment', 'request_code', 'created_at', 'completed_code', 'kind_of_request', 'branch_code')
                ->withCount('sharedUsers as total_shared')
                ->latest('created_at')
                ->get();


            // Initialize an array to hold the response data
            $response = $requestForms->map(function ($requestForm) use ($current_branch) {
                $branch = Branch::find($current_branch);
                $branchName = $branch->branch_code;

                // Decode the approvers_id fields, defaulting to empty arrays if null
                $notedByIds = $requestForm->noted_by ?? [];
                $approvedByIds = $requestForm->approved_by ?? [];
                $currency = $requestForm->currency;
                $allApproversIds = $requestForm?->approvalProcess
                ->pluck('user_id')
                ->merge($notedByIds)
                ->merge($approvedByIds)
                ->unique()
                ->values();

                // Fetch all approvers in one query
                $allApprovers = User::whereIn('id', $allApproversIds)
                    ->select('id', 'firstName', 'lastName', 'position', 'signature', 'branch', 'profile_picture')
                    ->get()
                    ->keyBy('id');

                // Fetch all approval statuses and comments in one query
                $approvalData = ApprovalProcess::whereIn('user_id', $allApproversIds)
                    ->where('request_form_id', $requestForm->id)
                    ->get()
                    ->keyBy('user_id');

                $attachments = $approvalData->pluck('attachment')->filter()->values()->all();

                // Format noted_by users
                $formattedNotedBy = $notedByIds
                    ? collect($notedByIds)->map(function ($userId) use ($allApprovers, $approvalData) {
                        if (isset($allApprovers[$userId])) {
                            $user = $allApprovers[$userId];
                            $approval = $approvalData[$userId] ?? null;

                            return [
                                'id' => $user->id,
                                'firstName' => $user->firstName,
                                'lastName' => $user->lastName,
                                'status' => $approval->status ?? '',
                                'comment' => $approval->comment ?? '',
                                'position' => $user->position,
                                'signature' => $user->signature,
                                'user' => $user
                            ];
                        }
                    })->filter()->values()->all()
                    : [];

                // Format approved_by users
                $formattedApprovedBy = $approvedByIds
                    ? collect($approvedByIds)->map(function ($userId) use ($allApprovers, $approvalData) {
                        if (isset($allApprovers[$userId])) {
                            $user = $allApprovers[$userId];
                            $approval = $approvalData[$userId] ?? null;

                            return [
                                'id' => $user->id,
                                'firstName' => $user->firstName,
                                'lastName' => $user->lastName,
                                'status' => $approval->status ?? '',
                                'comment' => $approval->comment ?? '',
                                'position' => $user->position,
                                'signature' => $user->signature,
                                'user' => $user
                            ];
                        }
                    })->filter()->values()->all()
                    : [];

                $otherUserIds = $allApproversIds?->diff($notedByIds)->diff($approvedByIds);
                     // Format avp-staff users
                $formattedAvpStaff = $otherUserIds
                    ? collect($otherUserIds)->map(function ($userId) use ($allApprovers, $approvalData) {
                        if (isset($allApprovers[$userId])) {
                            $user = $allApprovers[$userId];
                            $approval = $approvalData[$userId] ?? null;

                            return [
                                'id' => $user->id,
                                'firstName' => $user->firstName,
                                'lastName' => $user->lastName,
                                'status' => $approval->status ?? '',
                                'comment' => $approval->comment ?? '',
                                'position' => $user->position,
                                'signature' => $user->signature,
                                'user' => $user
                            ];
                        }
                    })->filter()->values()->all()
                    : [];

                // Get the pending approver
                $pendingApprover = $requestForm->approvalProcess()
                    ->where('status', 'Pending')
                    ->orderBy('level')
                    ->first()?->user;


                $approverName = "No Pending Approver";

                if ($pendingApprover) {
                    $approverFromFinanceStaff = AVPFinanceStaff::where('staff_id', $pendingApprover->id)->exists();

                    if ($approverFromFinanceStaff) {
                        $avpUser = User::where('position', 'AVP - Finance')->first();

                        if ($avpUser) {
                            $approverName = [
                                'approver_name' => "{$avpUser->firstName} {$avpUser->lastName}",
                            ];
                        } else {
                            $approverName = "No AVP Found";
                        }
                    } else {
                        $approverName = [
                            'approver_name' => "{$pendingApprover->firstName} {$pendingApprover->lastName}",

                        ];
                    }
                }


                $acronym = $branch?->acronym;

                return [
                    'id' => $requestForm->id,
                    'user_id' => $requestForm->user_id,
                    'form_type' => $requestForm->form_type,
                    'form_data' => $requestForm->form_data,
                    'cancelation_reason' => $requestForm->cancelation_reason,
                    'created_at' => $requestForm->created_at,
                    'status' => $requestForm->status,
                    'noted_by' => $formattedNotedBy,
                    'currency' => $currency,
                    'approved_by' => $formattedApprovedBy,
                    'avp_staff' => $formattedAvpStaff,
                    'requested_by' => Auth::user()->firstName . ' ' . Auth::user()->lastName,
                    'requested_signature' => Auth::user()->signature,
                    'requested_position' => Auth::user()->position,
                    'attachment' => $requestForm->attachment,
                    'pending_approver' => $approverName,
                    'request_code' => "$branchName-$requestForm->request_code",
                    'completed_code' => $requestForm->completed_code,
                    'user'  => $requestForm->user,
                    'approved_attachments' => $attachments,
                    'kind_of_request' => $requestForm->kind_of_request,
                    'branch' => [
                        'name' => (($acronym === "HO" ? 'ㅤ' : 'ㅤ' . $acronym . " - ") . $branch?->branch_name . 'ㅤ'),
                        'branch' => $branch?->branch
                    ],
                    'user_requested' => $requestForm?->branchCode?->branch_code,
                    'total_shared' => $requestForm?->total_shared ?? 0
                ];
            });

            return response()->json([
                'message' => 'Request forms retrieved successfully',
                'data' => paginateCollection($response, $perPage)
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving request forms',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //VIEW ALL REQUEST FORM OF ALL USERS
    public function viewAllRequests()
    {
        try {

            $users = RequestForm::select('id', 'user_id', 'form_type', 'form_data', 'currency', 'noted_by', 'approved_by', 'status', 'attachment')->get();

            return response()->json([
                'message' => 'Request form retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving request form',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //DELETE REQUEST
    public function deleteRequest($id)
    {
        try {
            $user = Auth::user();
            $requestForm = RequestForm::where('id', $id)->where('user_id', $user->id)->firstOrFail();

            // if (in_array($requestForm->status, ['Ongoing', 'Approved', 'Disapproved'])) {
            //     return response()->json([
            //         'message' => 'Request form cannot be deleted because it has already been processed'
            //     ], 400);
            // }

            $requestForm->delete();

            return response()->json([
                'message' => 'Request form deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while deleting the request form',
                'error' => $e->getMessage()
            ]);
        }
    }
    public function totalRequestSent($user_id)
    {

        try {

            $requestSent = RequestForm::where('user_id', $user_id)->count();
            $totalCompletedRequest = RequestForm::where('user_id', $user_id)->where('status', 'Completed')->count();
            $totalOngoingRequests = RequestForm::where('user_id', $user_id)->where('status', 'Ongoing')->count();
            $totalPendingRequest = RequestForm::where('user_id', $user_id)->where('status', 'Pending')->count();
            $totalDisapprovedRequest = RequestForm::where('user_id', $user_id)->where('status', 'Disapproved')->count();
            return response()->json([
                'message' => "Total number of request sent counted successfully",
                'totalRequestSent' => $requestSent,
                'totalCompletedRequest' => $totalCompletedRequest,
                'totalOngoingRequest' => $totalOngoingRequests,
                'totalPendingRequest' => $totalPendingRequest,
                'totalDisapprovedRequest' => $totalDisapprovedRequest

            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => "An error occured while counting the total request sent",
                'error' => $e->getMessage()
            ]);
        }
    }

    //TOTAL APPROVED REQUESTS BY USER

    public function totalApprovedRequests($user_id)
    {

        try {

            $totalApprovedRequests = RequestForm::where('user_id', $user_id)->where('status', 'Approved')->count();

            return response()->json([
                'message' => "Total number of approved request counted successfully",
                'totalApprovedRequest' => $totalApprovedRequests
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => "An error occured while counting the total number of approved request",
                'error' => $e->getMessage()
            ]);
        }
    }

    //TOTAL PENDING REQUESTS BY USER

    public function totalPendingRequests($user_id)
    {

        try {

            $totalPendingRequest = RequestForm::where('user_id', $user_id)->where('status', 'Pending')->count();

            return response()->json([
                'message' => "Total number of pending requests counted successfully",
                'totalPendingRequest' => $totalPendingRequest
            ]);
        } catch (\Exception $e) {
        }
    }

    //VIEW RECENT REQUESTS


    public function requestReports()
    {

        $per_page = request('per_page', 10);
        $search = request('search', '');
        $status = request('status', '');
        $date_to = request('date_to', '') ? Carbon::parse(request('date_to', ''))->endOfDay() : '';
        $date_from = request('date_from', '') ? Carbon::parse(request('date_from', ''))->startOfDay() : '';

        $requestReports = RequestForm::with('user', 'branchCode', 'approvalProcess.user')
            ->when(
                $search,
                fn($query)
                =>
                $query->where(
                    fn($subQuery)
                    =>
                    $subQuery->where('form_type', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('request_code', 'LIKE', "%{$search}%")
                        ->orWhere('completed_code', 'LIKE', "%{$search}%")
                        ->orWhereHas(
                            'branchCode',
                            fn($branch)
                            =>
                            $branch->where('branch_code', 'LIKE', "%{$search}%")
                                ->orWhere('branch_name', 'LIKE', "%{$search}%")
                                ->orWhere('branch', 'LIKE', "%{$search}%")
                                ->orWhere('acronym', 'LIKE', "%{$search}%")
                        )
                        ->orWhereHas(
                            'user',
                            fn($user)
                            =>
                            $user->where('firstName', 'LIKE', "%{$search}%")
                                ->orWhere('lastName', 'LIKE', "%{$search}%")
                        )
                )
            )
            ->when(
                $status,
                fn($query)
                =>
                $query->where('status', $status)
            )
            ->when(
                $date_to && $date_from,
                fn($query)
                =>
                $query->whereBetween('created_at', [$date_from, $date_to])
                    ->orWhereBetween('created_at', [$date_to, $date_from])
            )
            ->latest()
            ->paginate($per_page);

        $requestReports->getCollection()->transform(fn($requestReport) => [
            'id'                          => $requestReport->id,
            'user_id'                     => $requestReport->user_id,
            'form_type'                   => $requestReport->form_type,
            'form_data'                   => $requestReport->form_data,
            'created_at'                  => $requestReport->created_at,
            'updated_at'                  => $requestReport->updated_at,
            'user'                        => $requestReport->user,
            'currency'                    => $requestReport->currency,
            'branch'                      => [
                'name'                    => (($requestReport->branchCode->acronym === "HO" ? 'ㅤ' : 'ㅤ' . $requestReport->branchCode->acronym . " - ") . $requestReport->branchCode?->branch_name . 'ㅤ'),
                'branch'                  => $requestReport->branchCode?->branch
            ],
            'status'                      => $requestReport->status,
            'attachment'                  => $requestReport->attachment,
            'branch_code'                 => $requestReport->branchCode,
            'cancelation_reason'          => $requestReport->cancelation_reason,
            'request_code'                => $requestReport->request_code,
            'completed_code'              => $requestReport->completed_code,
            'requested_by'                => ($requestReport->user ? "{$requestReport->user->firstName} {$requestReport->user->lastName}" : "Unknown"),
            'requested_signature'         => ($requestReport->user ? "{$requestReport->user->signature}" : "Unknown"),
            'requested_position'          => ($requestReport->user ? "{$requestReport->user->position}" : "Unknown"),
            'approved_bies'               => $requestReport->approvalProcess
                ->whereIn('user_id', $requestReport->approved_by)
                ->map(fn($process)        => [
                    'comment'             => $process->comment,
                    'firstName'           => $process->user->firstName,
                    'lastName'            => $process->user->lastName,
                    'position'            => $process->user->position,
                    'signature'           => $process->user->signature,
                    'status'              => $process->status

                ])
                ->values(),
            'noted_bies'                  => $requestReport->approvalProcess
                ->whereIn('user_id', $requestReport->noted_by)
                ->map(fn($process)        => [
                    'comment'             => $process->comment,
                    'firstName'           => $process->user->firstName,
                    'lastName'            => $process->user->lastName,
                    'position'            => $process->user->position,
                    'signature'           => $process->user->signature,
                    'status'              => $process->status

                ])
                ->values(),
            'approval_process'            => $requestReport->approvalProcess->whereNotNull('comment')->values(),
            'approved_attachments'        => $requestReport->approvalProcess->whereNotNull('attachment')->values()
                ->pluck('attachment')
        ]);

        return response()->json($requestReports, 200);
    }

    public function approvalRequest()
    {
        $user_id = Auth::id();

        $request = RequestForm::query()
            ->with('approvalProcess')
            ->whereRelation('approvalProcess', 'user_id', $user_id);

        $total = (clone $request)->count();

        $total_completed = (clone $request)->where('status', 'Completed')
            ->count();

        $total_ongoing = (clone $request)->where('status', 'Ongoing')
            ->count();

        $total_pending = (clone $request)->where('status', 'Pending')
            ->count();

        $total_disapproved = (clone $request)->where('status', 'Disapproved')
            ->count();

        return response()->json([
            'message'           => "Approval request counted successfully",
            'total'             => $total,
            'total_completed'   => $total_completed,
            'total_ongoing'     => $total_ongoing,
            'total_pending'     => $total_pending,
            'total_disapproved' => $total_disapproved
        ], 200);
    }

    public function cancelRequest(Request $request, RequestForm $request_form)
    {
        $request->validate([
            'cancelation_reason' => ['required']
        ]);

        $request_form->update([
            'status'             => 'Canceled',
            'cancelation_reason' => $request->cancelation_reason
        ]);

        return response()->json([
            'message' => 'Request canceled successfully'
        ], 200);
    }

    public function statusPrintableChecking(RequestForm $request_form)
    {
        $is_not_printable = $request_form->status === 'Canceled';

        if ($is_not_printable) {
            return response()->json([
                'message' => 'Request form is not printable because it has been canceled'
            ], 400);
        }

        return response()->noContent();
    }
}
