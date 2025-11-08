<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Backup Update</title>
    <style type="text/css">
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            color: #495057;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .header {
            background-color: #007bff;
            color: #fff;
            padding: 20px;
            text-align: center;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 20px;
        }
        .card {
            background-color: #fff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 20px;
            padding: 15px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 500;
            color: #343a40;
            margin-bottom: 10px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .table th, .table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        .table th {
            background-color: #f8f9fa;
            font-weight: 500;
        }
        .btn {
            display: inline-block;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-primary {
            background-color: #007bff;
            color: #fff;
            border: 1px solid #007bff;
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        .footer {
            text-align: center;
            padding: 15px;
            font-size: 12px;
            color: #6c757d;
            background-color: #f8f9fa;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            Backup Update
        </div>
        <div class="content">
            <div class="card">
                <div class="card-title">Backup Details for Project: {{ $backup->project->name }}</div>
                <p>Hello,</p>
                <p>The backup process for your project <strong>{{ $backup->project->name }}</strong> has been completed with the following details:</p>
                <table class="table">
                    <tr>
                        <th>Filename</th>
                        <td>{{ $backup->file_name }}</td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>{{ $backup->status }}</td>
                    </tr>
                    <tr>
                        <th>Size</th>
                        <td>{{ number_format($backup->size / 1024 / 1024, 2) }} MB</td>
                    </tr>
                    <tr>
                        <th>Created At</th>
                        <td>{{ $backup->created_at }}</td>
                    </tr>
                </table>
                @if($backup->status === 'success')
                <p><a href="{{ config('app.url') }}/backups/manage-backups" class="btn btn-primary">Download Backup</a></p>
                @endif
            </div>
            <div class="footer">
                Thank you,<br>LaraSafe<br>
                &copy; 2025 Sudhir Rajai. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>