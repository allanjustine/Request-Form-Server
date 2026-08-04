<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Notifications\OngoingNotification;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\RequestForm;
use App\Models\CustomApprovers;
use App\Models\ApprovalProcess;
use Illuminate\Support\Facades\DB;
use App\Notifications\ApprovalProcessNotification;
use App\Notifications\EmployeeNotification;
use Illuminate\Support\Facades\Log;
use App\Notifications\ReturnRequestNotification;
use App\Notifications\PreviousReturnRequestNotification;
use App\Events\NotificationEvent;
use App\Models\Branch;
use App\Notifications\CompletedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ApprovalProcessController extends Controller
{
    //FOR APPROVER SIDE
    public function processRequestForm(Request $request, $request_form_id)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'action' => 'required|in:approve,disapprove',
            'comment' => 'nullable|string',
            'attachment.*' => 'nullable|file|mimes:png,jpg,jpeg,webp,ico,gif'
        ]);

        if (DB::table('a_v_p_finance_staff')->where('staff_id', $request->user_id)->exists()) {
            $request->validate([
                'comment' => 'required|string',
            ]);
        }

        // $currentUser = User::findOrFail($request->user_id);

        // Add conditional validation for VP-Staff requiring an attachment
        // if ($currentUser->position == 'Vice President') {
        //     $request->validate([
        //         'attachment' => 'required|array', // Ensure it's an array
        //         'attachment.*' => 'required|file|mimes:png,jpg,jpeg,webp,ico,gif'
        //     ]);
        // }

        // Initialize attachment variable
        $attachmentPaths = null; // Set default to null

        if ($request->hasFile('attachment')) {
            $attachmentPaths = []; // Initialize as an array if files exist
            foreach ($request->file('attachment') as $file) {
                // Store each file and collect paths
                $path = $file->store('request_form_comment_attachments', config('app.storage_disk'));
                $attachmentPaths[] = $path;
            }
        }

        // If no files were uploaded, $attachmentPaths will be null
        $user_id = $validated['user_id'];
        $action = $validated['action'];
        $comment = $validated['comment'];

        $requestForm = RequestForm::find($request_form_id);

        if ($requestForm->status === 'Canceled') {
            return response()->json([
                'message' => 'Ops! This request form has been canceled. Please refresh the list to see the updated status.',
            ], 400);
        }

        DB::beginTransaction();

        try {

            $approvalProcess = ApprovalProcess::where('request_form_id', $request_form_id)
                ->where('user_id', $user_id)
                ->where('status', 'Pending')
                ->first();

            if (!$requestForm) {
                return response()->json([
                    'message' => 'Request form not found or Already deleted by the requester.',
                ], 404);
            }

            if (!$approvalProcess) {
                return response()->json([
                    'message' => 'You are not authorized to approve this request form or it has already been processed.',
                ], 403);
            }

            $currentApprovalLevel = ApprovalProcess::where('request_form_id', $request_form_id)
                ->where('status', 'Pending')
                ->orderBy('level')
                ->first();

            if ((int) $currentApprovalLevel->user_id !== (int) $user_id) {
                return response()->json([
                    'message' => 'It is not your turn to approve this request form.',
                ], 403);
            }

            $approvalProcess->update([
                'status' => $action === 'approve' ? 'Approved' : 'Disapproved',
                'comment' => $comment,
                'attachment' => $attachmentPaths // Will be null if no attachments were uploaded
            ]);

            $code = $requestForm->request_code;
            $branch = $requestForm->branch_code;
            $name = Branch::find($branch);
            $branchName = $name->branch_code;
            $request_code = "$branchName-$code";

            if ($action === 'approve') {
                // Check if the current approver is the first approver
                $firstApprovalProcess = ApprovalProcess::where('request_form_id', $request_form_id)
                    ->orderBy('level')
                    ->first();

                if ($firstApprovalProcess && $firstApprovalProcess->user_id == $user_id) {
                    $requestForm->status = 'Ongoing';
                    $requestForm->save();

                    $user = User::where('id', $requestForm->user_id)->first();

                    $user->notify(new OngoingNotification($requestForm, 'ongoing', $user->firstName, $requestForm->form_type, $request_code));
                }

                $nextApprovalProcess = ApprovalProcess::where('request_form_id', $request_form_id)
                    ->where('status', 'Pending')
                    ->orderBy('level')
                    ->first();

                if ($nextApprovalProcess) {
                    $nextApprover = $nextApprovalProcess->user;
                    $firstname = $nextApprover->firstName;
                    $employee = $requestForm->user;
                    $requesterFirstname = $employee->firstName;
                    $requesterLasttname = $employee->lastName;
                    $nextApprover->notify(new ApprovalProcessNotification($nextApprovalProcess, $firstname, $requestForm, $requesterFirstname, $requesterLasttname));
                    event(new NotificationEvent(Auth::user()->id, $nextApprovalProcess->user->id));

                    // $user = User::where('id', $requestForm->user_id)->first();

                    // $user->notify(new OngoingNotification($requestForm, 'ongoing', $user->firstName, $requestForm->form_type, $request_code));

                    // Broadcast the notification count update
                    $message = 'You have a request form to approve';
                    $date = now();
                    $type = 'App\Notifications\ApprovalProcessNotification';
                    $read_at = null;
                    // event(new NotificationEvent($nextApprover->id, $message, $date,$type, $read_at));
                } else {
                    $requestForm->status = 'Completed';
                    $formtype = $requestForm->form_type;

                    $uniqueCode = $this->generateUniqueCode($formtype, $requestForm->branch_code);

                    $requestForm->update([
                        'completed_code' => $uniqueCode,
                    ]);
                    $requestForm->save();
                    $employee = $requestForm->user;
                    $firstname = $employee->firstName;
                    $employee->notify(new EmployeeNotification($requestForm, 'approved', $firstname, $formtype, $request_code, $comment));
                    Log::warning($requestForm->user);
                    if (!empty($requestForm->noted_by)) {
                        foreach ($requestForm->noted_by as $notedById) {
                            $notedByUser = User::find($notedById);
                            if ($notedByUser) {
                                $notedByUser->notify(new CompletedNotification());
                            }
                        }
                    }
                    $previousApprovalProcesses = ApprovalProcess::where('request_form_id', $request_form_id)
                        ->where('status', 'Approved')
                        ->orderBy('level', 'asc')
                        ->get();

                    foreach ($previousApprovalProcesses as $previousApprovalProcess) {
                        $previousApprover = $previousApprovalProcess->user;
                        $previousApprover->notify(new CompletedNotification());
                    }

                    // Notify the approved_by users
                    if (!empty($requestForm->approved_by)) {
                        foreach ($requestForm->approved_by as $approvedById) {
                            $approvedByUser = User::find($approvedById);
                            if ($approvedByUser) {
                                $approvedByUser->notify(new CompletedNotification());
                            }
                        }
                    }
                    // Broadcast the notification count update
                    $message = 'Your request has been ' . $requestForm->status;
                    $date = now();
                    $type = 'App\Notifications\EmployeeNotification';
                    $read_at = null;
                    // event(new NotificationEvent($employee->id, $message, $date, $type, $read_at));
                }
            } else {
                $requestForm->status = 'Disapproved';
                $requestForm->save();
                $formtype = $requestForm->form_type;
                $employee = $requestForm->user;
                $firstname = $employee->firstName;
                $approverFirstname = $approvalProcess->user->firstName;
                $approverLastname = $approvalProcess->user->lastName;
                $employee->notify(new ReturnRequestNotification($requestForm, 'disapproved', $firstname, $approverFirstname, $approverLastname, $request_code, $comment));

                // Broadcast the notification count update
                $message = 'Your request has been returned because it is ' . $requestForm->status;
                $date = now();
                $type = 'App\Notifications\ReturnRequestNotification';
                $read_at = null;
                event(new NotificationEvent(Auth::user()->id, $requestForm->user->id));

                // Notify all previous approvers and update their status to "Rejected by [name]"
                $previousApprovalProcesses = ApprovalProcess::where('request_form_id', $request_form_id)
                    ->where('status', 'Approved')
                    ->orderBy('level', 'asc') // Ensure we are processing in order
                    ->get();

                foreach ($previousApprovalProcesses as $previousApprovalProcess) {
                    $previousApprover = $previousApprovalProcess->user;
                    $prevFirstName = $previousApprover->firstName;

                    // Update the previous approver's status to "Rejected by [name]"
                    $previousApprovalProcess->update([
                        'status' => "Rejected by $approverFirstname $approverLastname",
                    ]);

                    $requesterFirstname = $employee->firstName;
                    $requesterLastname = $employee->lastName;
                    $previousApprover->notify(new PreviousReturnRequestNotification($requestForm, 'disapproved', $prevFirstName, $approverFirstname, $approverLastname, $comment, $requesterFirstname, $requesterLastname));

                    // Broadcast the notification count update
                    $message = 'The ' . $requestForm->form_type . ' requested by ' . $requesterFirstname . ' ' . $requesterLastname . ' has been disapproved by ' . $approverFirstname . ' ' . $approverLastname;
                    $date = now();
                    $type = 'App\Notifications\PreviousReturnRequestNotification';
                    $read_at = null;
                    event(new NotificationEvent(Auth::user()->id, $previousApprovalProcess->user->id));
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Request form processed successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

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
        ];

        if (isset($prefixes[$formType]) && $branchId) {
            $count = RequestForm::where('form_type', $formType)
                ->where('branch_code', $branchId)
                ->where('status', 'Completed')
                ->count();
            $nextNumber = str_pad($count + 1, 7, '0', STR_PAD_LEFT);
            return $prefixes[$formType] . $nextNumber;
        }

        return null;
    }

    public function approvalProcessPending()
    {
        $user_id = Auth::id();
        // try {
        // Retrieve all approval processes where the current user is involved
        $approvalProcesses = ApprovalProcess::where('user_id', $user_id)
            ->where('status', 'Pending')
            ->whereDoesntHaveRelation('requestForm', 'status', 'Canceled')
            ->orderBy('level')
            ->with(['requestForm.user', 'user']) // Eager load request form with user
            ->get();

        // Process each approval process
        $transformedApprovalProcesses = $approvalProcesses->map(function ($approvalProcess) use ($user_id) {

            $requestForm = $approvalProcess->requestForm;
            $requester = $requestForm?->user; // Eager loaded user

            // Check if all previous levels are approved
            $previousLevelsApproved = $requestForm?->approvalProcess
                ->where('level', '<', $approvalProcess->level)
                ->every(function ($process) {
                    return $process->status == 'Approved';
                });

            // Determine if it's the user's turn to approve
            $isUserTurn = $previousLevelsApproved && $approvalProcess->status == 'Pending' && $approvalProcess->user_id == $user_id;

            // Include request forms where the previous level has statuses of Approved, Disapproved, or Rejected by...
            $isRelevantStatus = in_array($approvalProcess->status, ['Approved', 'Completed', 'Disapproved']) ||
                preg_match('/^Rejected by/', $approvalProcess->status);

            if (!$isRelevantStatus && !$isUserTurn) {
                return null; // Skip if the status is not relevant and it's not the user's turn to approve
            }

            // Determine if the current user is the last approver
            $isLastApprover = $requestForm?->approvalProcess()
                ->where('status', 'Pending')
                ->where('level', '>', $approvalProcess->level)
                ->count() === 0;

            // Determine if the request form has been disapproved
            $isDisapproved = $requestForm?->approvalProcess()
                ->where('status', 'Disapproved')
                ->count() > 0;

            // Determine the next approver
            $pendingApproverr = $requestForm?->approvalProcess()
                ->where('status', 'Pending')
                ->orderBy('level')
                ->first()?->user; // Get the next approver

            // Fetch approvers details
            $notedByIds = $requestForm->noted_by ?? [];
            $approvedByIds = $requestForm->approved_by ?? [];

            // Combine all approvers and other involved user IDs
            $allUserIds = $requestForm?->approvalProcess
                ->pluck('user_id')
                ->merge($notedByIds)
                ->merge($approvedByIds)
                ->unique()
                ->values();

            $allApprovers = User::whereIn('id', $allUserIds)
                ->select('id', 'firstName', 'lastName', 'position', 'signature', 'branch')
                ->get()
                ->keyBy('id');

            $approvalData = ApprovalProcess::whereIn('user_id', $allUserIds)
                ->where('request_form_id', $requestForm->id)
                ->get()
                ->keyBy('user_id');

            $attachments = $approvalData->pluck('attachment')->filter()->values()->all();

            // Helper function to format approver data with status and comment

            $formatApproverData = function ($userId) use ($allApprovers, $approvalData) {
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
                        'signature' => $user->signature
                    ];
                }
                return null;
            };
            // Format noted_by users
            $formattedNotedBy = collect($notedByIds)
                ->map($formatApproverData)
                ->filter()
                ->values()
                ->all();

            // Format approved_by users
            $formattedApprovedBy = collect($approvedByIds)
                ->map($formatApproverData)
                ->filter()
                ->values()
                ->all();

            // Format other approvers who are not listed in noted_by or approved_by
            $otherUserIds = $allUserIds?->diff($notedByIds)->diff($approvedByIds);
            $formattedOtherApprovers = collect($otherUserIds)
                ->map($formatApproverData)
                ->filter()
                ->values()
                ->all();

            // Prepare the response format
            $approver = $approvalProcess->user; // Eager loaded approver
            $branch = $requestForm?->branch_code;
            $branchNa = Branch::find($branch);
            $acronym = $branchNa?->acronym;
            $branchName = $branchNa?->branch_code;
            // Determine pending approver
            $pendingApprover = null;
            if ($isUserTurn && $isLastApprover) {
                $pendingApprover = "{$approver->firstName} {$approver->lastName}";
            } elseif ($isDisapproved) {
                $pendingApprover = 'No Pending Approver';
            } else {
                $pendingApprover = $pendingApproverr ? "{$pendingApproverr->firstName} {$pendingApproverr->lastName}" : 'No Pending Approver';
            }

            return [
                'id' => $requestForm?->id,
                'form_type' => $requestForm?->form_type,
                'form_data' => $requestForm?->form_data, // Assuming form_data is JSON
                'status' => $approvalProcess->status, // Include the actual status of the approval process
                'completed_status' => $requestForm?->status, // Include the actual status of the approval process
                'created_at' => $approvalProcess->created_at,
                'currency' => $requestForm?->currency,
                'updated_at' => $approvalProcess->updated_at,
                'user_id' => $requestForm?->user_id,
                'requested_by' => ($requester ? "{$requester->firstName} {$requester->lastName}" : "Unknown"),
                'requested_signature' => ($requester ? "{$requester->signature}" : "Unknown"), // Handle null requester
                'requested_position' => ($requester ? "{$requester->position}" : "Unknown"), // Handle null requester
                'noted_by' => $formattedNotedBy,
                'approved_by' => $formattedApprovedBy,
                'avp_staff' => $formattedOtherApprovers, // Include other approvers not listed in noted_by or approved_by
                'pending_approver' => $pendingApprover, // Update pending approver logic
                'attachment' => $requestForm?->attachment,
                'branch' => (($acronym === "HO" ? 'ㅤ' : 'ㅤ' . $acronym . " - ") . $branchNa?->branch_name . 'ㅤ'),
                'request_code' => "{$branchName}-{$requestForm?->request_code}",
                'approved_attachment' => $attachments,
                'completed_code' => $requestForm?->completed_code
            ];
        })->filter()->values(); // Filter out null values

        // } catch (\Exception $e) {
        //     // Log the exception for debugging purposes
        //     Log::error('Error in getRequestFormsForApproval', ['error' => $e->getMessage()]);

        //     return response()->json([
        //         'message' => 'An error occurred',
        //         'error' => 'An error occurred while processing your request.',
        //     ], 500);
        // }
        $counts = $transformedApprovalProcesses->count();

        return response()->json($counts, 200);
    }

    public function getRequestFormsForApproval($user_id)
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
        $dateSearch = "";

        if (Carbon::hasFormat($search, 'F j, Y')) {

            $dateSearch = Carbon::parse($search)->format("Y-m-d");
        }

        // try {
        // Retrieve all approval processes where the current user is involved
        $approvalProcesses = ApprovalProcess::where('user_id', $user_id)
            ->whereDoesntHaveRelation('requestForm', 'status', 'Canceled')
            ->orderBy('level')
            ->when($search, fn($query) =>
            $query->where(fn($subQuery) =>
            $subQuery->whereHas('requestForm', fn($triQuery) =>
            $triQuery->where('form_type', 'LIKE', "%{$search}%")
                ->orWhere('request_code', 'LIKE', "%{$search}%")
                ->orWhereDate("created_at", $dateSearch))))
            ->with(['requestForm.user', 'requestForm.branchCode', 'user']) // Eager load request form with user
            ->when(
                $status_req !== "ALL",
                fn($query)
                =>
                $query->whereRelation('requestForm', 'status', $status_req)
            )
            ->when(
                $status_req === "Pending",
                fn($query)
                =>
                $query->orWhereRelation('requestForm', 'status', 'Ongoing')
                    ->where('status', 'Pending')
                    ->where('user_id', $user_id)
            )
            ->get();

        // Process each approval process
        $transformedApprovalProcesses = $approvalProcesses->map(function ($approvalProcess) use ($user_id) {

            $requestForm = $approvalProcess->requestForm;
            $requester = $requestForm?->user; // Eager loaded user

            // Check if all previous levels are approved
            $previousLevelsApproved = $requestForm?->approvalProcess
                ->where('level', '<', $approvalProcess->level)
                ->every(function ($process) {
                    return $process->status == 'Approved';
                });

            // Determine if it's the user's turn to approve
            $isUserTurn = $previousLevelsApproved && $approvalProcess->status == 'Pending' && $approvalProcess->user_id == $user_id;

            // Include request forms where the previous level has statuses of Approved, Disapproved, or Rejected by...
            $isRelevantStatus = in_array($approvalProcess->status, ['Approved', 'Completed', 'Disapproved']) ||
                preg_match('/^Rejected by/', $approvalProcess->status);

            if (!$isRelevantStatus && !$isUserTurn) {
                return null; // Skip if the status is not relevant and it's not the user's turn to approve
            }

            // Determine if the current user is the last approver
            $isLastApprover = $requestForm?->approvalProcess()
                ->where('status', 'Pending')
                ->where('level', '>', $approvalProcess->level)
                ->count() === 0;

            // Determine if the request form has been disapproved
            $isDisapproved = $requestForm?->approvalProcess()
                ->where('status', 'Disapproved')
                ->count() > 0;

            // Determine the next approver
            $pendingApproverr = $requestForm?->approvalProcess()
                ->where('status', 'Pending')
                ->orderBy('level')
                ->first()?->user; // Get the next approver

            // Fetch approvers details
            $notedByIds = $requestForm->noted_by ?? [];
            $approvedByIds = $requestForm->approved_by ?? [];

            // Combine all approvers and other involved user IDs
            $allUserIds = $requestForm?->approvalProcess
                ->pluck('user_id')
                ->merge($notedByIds)
                ->merge($approvedByIds)
                ->unique()
                ->values();

            $allApprovers = User::whereIn('id', $allUserIds ?? [])
                ->select('id', 'firstName', 'lastName', 'position', 'signature', 'branch')
                ->get()
                ->keyBy('id');

            $approvalData = ApprovalProcess::whereIn('user_id', $allUserIds ?? [])
                ->where('request_form_id', $requestForm?->id)
                ->get()
                ->keyBy('user_id');

            $attachments = $approvalData->pluck('attachment')->filter()->values()->all();

            // Helper function to format approver data with status and comment

            $formatApproverData = function ($userId) use ($allApprovers, $approvalData) {
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
                        'signature' => $user->signature
                    ];
                }
                return null;
            };
            // Format noted_by users
            $formattedNotedBy = collect($notedByIds)
                ->map($formatApproverData)
                ->filter()
                ->values()
                ->all();

            // Format approved_by users
            $formattedApprovedBy = collect($approvedByIds)
                ->map($formatApproverData)
                ->filter()
                ->values()
                ->all();

            // Format other approvers who are not listed in noted_by or approved_by
            $otherUserIds = $allUserIds?->diff($notedByIds)->diff($approvedByIds);
            $formattedOtherApprovers = collect($otherUserIds)
                ->map($formatApproverData)
                ->filter()
                ->values()
                ->all();

            // Prepare the response format
            $approver = $approvalProcess->user; // Eager loaded approver
            $branch = $requestForm?->branch_code;
            $branchNa = Branch::find($branch);
            $acronym = $branchNa?->acronym;
            $branchName = $branchNa?->branch_code;
            // Determine pending approver
            $pendingApprover = null;
            if ($isUserTurn && $isLastApprover) {
                $pendingApprover = "{$approver->firstName} {$approver->lastName}";
            } elseif ($isDisapproved) {
                $pendingApprover = 'No Pending Approver';
            } else {
                $pendingApprover = $pendingApproverr ? "{$pendingApproverr->firstName} {$pendingApproverr->lastName}" : 'No Pending Approver';
            }

            if (!$approvalProcess->requestForm) {
                return;
            }

            return [
                'id' => $requestForm?->id,
                'form_type' => $requestForm?->form_type,
                'form_data' => $requestForm?->form_data, // Assuming form_data is JSON
                'status' => $approvalProcess->status, // Include the actual status of the approval process
                'completed_status' => $requestForm?->status, // Include the actual status of the approval process
                // 'created_at' => $approvalProcess->created_at,
                'currency' => $requestForm?->currency,
                'updated_at' => $approvalProcess->updated_at,
                'user_id' => $requestForm?->user_id,
                'requested_by' => ($requester ? "{$requester->firstName} {$requester->lastName}" : "Unknown"),
                'requested_signature' => ($requester ? "{$requester->signature}" : "Unknown"), // Handle null requester
                'requested_position' => ($requester ? "{$requester->position}" : "Unknown"), // Handle null requester
                'noted_by' => $formattedNotedBy,
                'approved_by' => $formattedApprovedBy,
                'avp_staff' => $formattedOtherApprovers, // Include other approvers not listed in noted_by or approved_by
                'pending_approver' => $pendingApprover, // Update pending approver logic
                'attachment' => $requestForm?->attachment,
                'branch' => [
                    'name' => (($acronym === "HO" ? 'ㅤ' : 'ㅤ' . $acronym . " - ") . $branchNa?->branch_name . 'ㅤ'),
                    'branch' => $branchNa?->branch,
                    'acronym' => $acronym
                ],
                'request_code' => "{$branchName}-{$requestForm?->request_code}",
                'approved_attachment' => $attachments,
                'completed_code' => $requestForm?->completed_code,
                'created_at' => $requestForm?->created_at,
                'user_requested' => $approvalProcess?->requestForm?->branchCode?->branch_code,
                'cancelation_reason' => $requestForm?->cancelation_reason,
            ];
        })
            ->filter()
            ->sortByDesc('created_at') // Filter out null values
            ->values();


        return response()->json([
            'message'                         => 'Approval processes you are involved in',
            'request_forms_paginated'         => paginateCollection($transformedApprovalProcesses, $perPage), // Ensure it's a zero-indexed array
            'request_forms'                   => $transformedApprovalProcesses, // Ensure it's a zero-indexed array
            'request_pending_count'           => $transformedApprovalProcesses->count()
        ], 200);

        // } catch (\Exception $e) {
        //     // Log the exception for debugging purposes
        //     Log::error('Error in getRequestFormsForApproval', ['error' => $e->getMessage()]);

        //     return response()->json([
        //         'message' => 'An error occurred',
        //         'error' => 'An error occurred while processing your request.',
        //     ], 500);
        // }
    }


    //vIEW INDIVIDUAL REQUEST TO APPROVE
    public function viewSingleRequestForm($request_form_id)
    {
        try {
            // Fetch the request form
            $requestForm = RequestForm::findOrFail($request_form_id);

            // Fetch custom approvers for this specific request form
            $customApprovers = CustomApprovers::where('id', $requestForm->approvers_id)->first();
            $notedByIds = $customApprovers ? (is_array($customApprovers->noted_by) ? $customApprovers->noted_by : json_decode($customApprovers->noted_by, true)) : [];
            $approvedByIds = $customApprovers ? (is_array($customApprovers->approved_by) ? $customApprovers->approved_by : json_decode($customApprovers->approved_by, true)) : [];

            // Fetch noted by users and their approval statuses
            $notedBy = User::whereIn('id', $notedByIds)->select('id', 'firstName', 'lastName', 'position', 'signature', 'branch')->get()->keyBy('id');
            $notedStatus = ApprovalProcess::whereIn('user_id', $notedByIds)
                ->where('request_form_id', $request_form_id)
                ->pluck('status', 'user_id');


            $notedComment = ApprovalProcess::whereIn('user_id', $notedByIds)
                ->where('request_form_id', $request_form_id)
                ->pluck('comment', 'user_id');


            // Fetch approved by users and their approval statuses
            $approvedBy = User::whereIn('id', $approvedByIds)->select('id', 'firstName', 'lastName', 'position', 'signature', 'branch')->get()->keyBy('id');
            $approvedStatus = ApprovalProcess::whereIn('user_id', $approvedByIds)
                ->where('request_form_id', $request_form_id)
                ->pluck('status', 'user_id');


            $approvedComment = ApprovalProcess::whereIn('user_id', $approvedByIds)
                ->where('request_form_id', $request_form_id)
                ->pluck('comment', 'user_id');


            // Fetch comments for the request form
            /*  $comments = ApprovalProcess::where('request_form_id', $request_form_id)
                 //->whereIn('status', ['approved', 'disapproved'])
                 ->join('users', 'approval_processes.user_id', '=', 'users.id')
                 ->select('approval_processes.comment')
                 //->orderBy('approval_processes.level')
                 ->get(); */

            // Format notedby and approvedby according to the desired structure
            $formattedNotedBy = [];
            foreach ($notedByIds as $userId) {
                if (isset($notedBy[$userId])) {
                    $formattedNotedBy[] = [

                        'firstname' => $notedBy[$userId]->firstName,
                        'lastname' => $notedBy[$userId]->lastName,
                        'status' => $notedStatus[$userId] ?? '',
                        'comment' => isset($notedComment[$userId]) ? $notedComment[$userId] : '',
                        'position' => $notedBy[$userId]->position,
                        'signature' => $notedBy[$userId]->signature,

                    ];
                }
            }

            $formattedApprovedBy = [];
            foreach ($approvedByIds as $userId) {
                if (isset($approvedBy[$userId])) {
                    $formattedApprovedBy[] = [
                        'firstname' => $approvedBy[$userId]->firstName,
                        'lastname' => $approvedBy[$userId]->lastName,
                        'status' => $approvedStatus[$userId] ?? '',
                        'comment' => isset($approvedComment[$userId]) ? $approvedComment[$userId] : '',
                        'position' => $approvedBy[$userId]->position,
                        'signature' => $approvedBy[$userId]->signature,


                    ];
                }
            }

            $response = [
                'message' => 'Request form retrieved successfully',
                'request_form' => $requestForm,
                'notedby' => $formattedNotedBy,
                'approvedby' => $formattedApprovedBy,

            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving the request form',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}