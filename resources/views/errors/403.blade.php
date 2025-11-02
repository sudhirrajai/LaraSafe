{{-- resources/views/errors/403.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - LaraSafe</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .container {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 40px;
            border-radius: 16px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            max-width: 500px;
        }
        h1 {
            font-size: 5rem;
            margin: 0;
            text-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        p {
            font-size: 1.2rem;
            margin: 20px 0;
            opacity: 0.9;
        }
        .btn {
            background: #fff;
            color: #667eea;
            padding: 12px 30px;
            border: none;
            border-radius: 50px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
            transition: 0.3s;
        }
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>403</h1>
        <h2>Access Denied</h2>
        <p>You don't have permission to perform this action.</p>
        <a href="{{ url()->previous() }}" class="btn">Go Back</a>
        <a href="{{ route('dashboard') }}" class="btn" style="margin-left: 10px;">Dashboard</a>
    </div>

</body>
</html>