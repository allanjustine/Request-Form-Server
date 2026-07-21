# Request Form - Laravel Backend API

A robust RESTful API for managing enterprise request forms with advanced approval workflows, user authentication, and multi-role authorization.

## Server Flow

### Request Processing Workflow

```
┌──────────────────────────────────────────────────────────────┐
│                   1. REQUEST SUBMISSION                      │
│  POST /api/create-request                                    │
│  - Validate form data                                        │
│  - Create RequestForm record                                 │
│  - Store attachments                                         │
│  - Initialize ApprovalProcess                                │
└──────────────────────────────────────────────────────────────┘
                           ↓
┌──────────────────────────────────────────────────────────────┐
│               2. APPROVAL ROUTING ENGINE                      │
│  - Calculate approval chain based on:                        │
│    • Request type & amount                                   │
│    • Requester's department/branch                           │
│    • Custom approver settings                                │
│    • Approval rules & thresholds                             │
│  - Assign ApprovalSteps in order                             │
└──────────────────────────────────────────────────────────────┘
                           ↓
┌──────────────────────────────────────────────────────────────┐
│            3. NOTIFY APPROVERS (Async)                       │
│  - Send email notifications                                  │
│  - Create notification records                               │
│  - Trigger WebSocket updates                                 │
│  - Add to approver's pending queue                           │
└──────────────────────────────────────────────────────────────┘
                           ↓
┌──────────────────────────────────────────────────────────────┐
│          4. APPROVER DECISION (Parallel)                     │
│  POST /api/request-forms/{id}/process                        │
│  - Retrieve ApprovalStep details                             │
│  - Validate approver credentials                             │
│  - Accept approval/rejection with comment                    │
│  - Record ApprovedBy entry                                   │
└──────────────────────────────────────────────────────────────┘
                           ↓
              ┌────────────┴────────────┐
              ↓                         ↓
        ┌──────────────┐        ┌──────────────┐
        │ APPROVED ✓   │        │ REJECTED ✗   │
        └──────────────┘        └──────────────┘
              ↓                         ↓
    ┌─────────────────────┐    ┌──────────────────┐
    │ Next Approver in    │    │ Request Rejected │
    │ Chain or COMPLETED  │    │ Requester Notified
    └─────────────────────┘    └──────────────────┘
              ↓
┌──────────────────────────────────────────────────────────────┐
│         5. FINAL STATUS UPDATE & NOTIFICATIONS               │
│  - Update RequestForm status                                 │
│  - Archive ApprovalProcess                                   │
│  - Send completion emails                                    │
│  - Create audit log entry                                    │
│  - Notify requester via push/email                           │
└──────────────────────────────────────────────────────────────┘
```

### Authentication & Authorization Flow

```
┌─────────────────┐
│ User Login      │
│ POST /api/login │
└────────┬────────┘
         ↓
┌──────────────────────────────────┐
│ Validate Credentials             │
│ - Check email exists             │
│ - Verify password hash           │
│ - Get user role                  │
└────────┬─────────────────────────┘
         ↓
┌──────────────────────────────────┐
│ Issue Sanctum API Token          │
│ Token contains:                  │
│ - user_id                        │
│ - user_email                     │
│ - user_role                      │
│ - permissions                    │
└────────┬─────────────────────────┘
         ↓
┌──────────────────────────────────┐
│ Client Stores Token              │
│ Added to Authorization header    │
│ for all subsequent requests      │
└────────┬─────────────────────────┘
         ↓
┌──────────────────────────────────┐
│ Middleware Verification          │
│ Every protected route checks:    │
│ - Token validity                 │
│ - User permissions               │
│ - Role-based access              │
└──────────────────────────────────┘
```

## Overview

This Laravel backend powers a comprehensive request management system that handles:

- **Request Form Management** - Create, update, and track various request types (stock, cash advance, purchase, check issuance, etc.)
- **Multi-Level Approval Workflows** - Automated approval routing based on departments, amounts, and roles
- **User Management** - Role-based access control with different permission levels
- **Attachment Handling** - Secure file upload and storage for request documentation
- **Real-Time Notifications** - Event broadcasting for approval status updates
- **Audit Trail** - Complete tracking of all actions and approvals
- **Feedback System** - Comments and feedback from approvers during the approval process

## Main Features

### 🔐 Authentication & Security

- **Sanctum API Tokens** - Laravel Sanctum for stateless API authentication
- **Role-Based Access Control** - Admin, Manager, Approver, Requester, Branch Head
- **Password Management** - Secure hashing, reset functionality
- **Email Verification** - User registration validation
- **User Profile Management** - Update credentials, signature, profile picture
- **Change Password** - Secure password update flow
- **Request Access Control** - Granular permissions per resource

### 📝 Request Form Management

- **Multiple Request Types**:
    - Stock Requests
    - Cash Advance Requests
    - Purchase Orders
    - Check Issuance
    - Cash Disbursement
    - Discount Requests
- **Status Tracking**: Draft → Submitted → Pending → Approved/Rejected → Completed
- **Form Versioning**: Track updates to requests
- **Request History**: Full audit trail of all changes
- **Soft Deletes**: Archive requests without permanent deletion

### ✅ Approval Workflows

- **Automated Routing**: Route requests based on amount, department, type
- **Custom Approver Assignment**: Define approval chains per user/department
- **Multi-Step Approvals**: Sequential or conditional approval steps
- **Conditional Logic**: Different approvers based on request criteria
- **Approval Comments**: Feedback and notes from approvers
- **Parallel Approvals**: Multiple approvers in same level
- **Amount Thresholds**: Different approval chains for different amounts

### 👥 User Management

- **Department Management**: Organizational structure
- **Branch Management**: Multi-branch support
- **Position Hierarchy**: Job titles and roles
- **Area Manager Assignment**: Regional management
- **Branch Head Management**: Branch-level approval authority
- **Custom Approvers**: Define approval chains per user
- **AVP Finance Staff**: Specialized finance approver role
- **User Verification**: Email verification workflow
- **Profile Management**: User data and preferences

### 📎 Attachment Handling

- **File Upload**: Secure file storage
- **Storage Management**: Organized file structure
- **Access Control**: Secure file retrieval
- **File Deletion**: Remove attachments when needed
- **Multiple File Support**: Attach multiple files per request

### 🔔 Notification System

- **Real-Time Broadcasting**: WebSocket via Pusher/Reverb
- **Email Notifications**: Approval status emails
- **In-App Notifications**: Notification history & center
- **Notification Preferences**: User notification settings
- **Unread Tracking**: Count and mark unread notifications
- **Bulk Notifications**: Notify multiple users
- **Feedback Notifications**: Alert on new feedback

### 📊 Reports & Analytics

- **Request Status Reports**: View request statistics
- **Approval History**: Track approval timelines
- **User Activity Logs**: Audit trail of actions
- **Feedback Analysis**: Review comments and feedback
- **Request Filtering**: Advanced search and filtering

### 🔄 Sharing & Collaboration

- **Share Requests**: Grant access to team members
- **Collaborative Feedback**: Multiple approvers can comment
- **Request Delegation**: Approvers can delegate requests

## Tech Stack

- **Framework**: Laravel 11
- **Language**: PHP 8.2+
- **Database**: MySQL
- **Authentication**: JWT (Laravel Sanctum)
- **Real-Time**: Pusher/Reverb for WebSocket broadcasting
- **API**: RESTful architecture with JSON responses
- **Validation**: Laravel Form Requests
- **Testing**: PHPUnit
- **Queue**: Laravel Queue for background jobs

## Prerequisites

- PHP 8.2 or higher
- Composer
- MySQL 5.7+
- Node.js 16+ (for real-time features)

## Installation & Setup

### 1. Clone Repository

```bash
cd server
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Environment Configuration

```bash
cp .env.example .env
```

Update `.env` with your database and API credentials:

```env
APP_NAME="Request Form API"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=request_form_db
DB_USERNAME=root
DB_PASSWORD=

SANCTUM_STATEFUL_DOMAINS=localhost:3000
```

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Run Migrations & Seeders

```bash
php artisan migrate
php artisan db:seed
```

### 6. Create Storage Link

```bash
php artisan storage:link
```

### 7. Start Development Server

```bash
php artisan serve
```

The API will be available at `http://localhost:8000`

## Key Features

### 🔐 Authentication & Authorization

- User registration and login with JWT tokens
- Role-based access control (Admin, Manager, Approver, etc.)
- Password reset functionality
- Profile management

### 📝 Request Form Management

- CRUD operations for various request types
- Status tracking (Draft, Submitted, Approved, Rejected, etc.)
- Attachment support
- Request history and audit logs

### ✅ Approval Workflows

- Automated approval routing
- Multi-step approval chains
- Conditional approvals based on amount thresholds
- Custom approver assignment
- Approval comments and feedback

### 👥 User Management

- Department and branch management
- Position hierarchy
- Area manager assignments
- Branch head authorization
- User access requests and approval

### 🔔 Notifications

- Real-time status updates via WebSocket
- Email notifications for approvers
- Notification history
- User preferences

### 📊 Reports & Analytics

- Request status reports
- Approval performance metrics
- User activity tracking
- Feedback analysis

## API Endpoints

### Authentication

- `POST /api/register` - User registration
- `POST /api/login` - User login
- `POST /api/password/email` - Request password reset
- `POST /api/reset-password` - Reset password

### Request Forms

- `GET /api/request-forms` - List all requests
- `POST /api/request-forms` - Create new request
- `GET /api/request-forms/{id}` - Get request details
- `PUT /api/request-forms/{id}` - Update request
- `DELETE /api/request-forms/{id}` - Delete request

### Approvals

- `POST /api/approve-request` - Approve a request
- `POST /api/reject-request` - Reject a request
- `GET /api/pending-approvals` - Get pending approvals
- `POST /api/add-comment` - Add approval comment

### Users & Roles

- `GET /api/users` - List users
- `POST /api/users` - Create user
- `GET /api/users/{id}` - Get user details
- `POST /api/change-password` - Change password
- `GET /api/get-role/{id}` - Get user role

### Configuration

- `GET /api/positions` - List positions
- `GET /api/view-branch` - List branches
- `GET /api/suppliers` - List suppliers
- `GET /api/banks` - List banks

## Database Structure

### Core Models

- **User** - Application users
- **RequestForm** - Main request entity
- **Attachment** - File attachments for requests
- **ApprovalProcess** - Approval workflow definitions
- **ApprovalStep** - Individual approval steps
- **ApprovedBy** - Approval history records

### Configuration Models

- **Department** - Organizational departments
- **Branch** - Office branches
- **Position** - User positions
- **Bank** - Bank information
- **Supplier** - Vendor/supplier data

## Testing

```bash
# Run all tests
php artisan test

# Run specific test
php artisan test tests/Feature/RequestFormTest.php

# Run with coverage
php artisan test --coverage
```

## Deployment

### Using Docker

```bash
docker-compose up -d
docker-compose exec app php artisan migrate
```

### Traditional Hosting

1. Upload code to server
2. Run `composer install --no-dev`
3. Configure `.env` for production
4. Run migrations: `php artisan migrate --force`
5. Set up cron job for queue worker
6. Configure web server (Nginx/Apache)

## Queue Jobs

Run queue worker for background jobs:

```bash
php artisan queue:work
```

## Troubleshooting

### Database Connection Issues

- Verify MySQL is running
- Check `.env` database credentials
- Ensure database exists

### Storage/Upload Issues

- Run `php artisan storage:link`
- Check `storage/` folder permissions (755)
- Verify `public/storage` symlink

### Authentication Issues

- Clear JWT cache: `php artisan cache:clear`
- Regenerate API token if needed

## Documentation

- [Laravel Documentation](https://laravel.com/docs)
- [Sanctum Authentication](https://laravel.com/docs/sanctum)
- [Database Migrations](https://laravel.com/docs/migrations)
- [Eloquent ORM](https://laravel.com/docs/eloquent)

## License

This project is licensed under the MIT License.

## Support

For API documentation and detailed endpoint specifications, check the Postman collection or API documentation file included in the project.
