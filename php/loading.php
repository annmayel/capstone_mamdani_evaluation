<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Loading - Capstone Evaluation System</title>
  <link rel="stylesheet" href="assets/style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .loading-page {
      background: linear-gradient(135deg, var(--primary-dark) 0%, var(--deep-black) 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 40px;
    }

    .loader-container {
      position: relative;
      width: 120px;
      height: 120px;
      perspective: 1000px;
    }

    .loader-cube {
      width: 100%;
      height: 100%;
      position: relative;
      transform-style: preserve-3d;
      animation: rotateCube 3s infinite ease-in-out;
    }

    .loader-cube div {
      position: absolute;
      width: 120px;
      height: 120px;
      background: linear-gradient(135deg, var(--accent-brown) 0%, #8B6B4F 100%);
      border: 2px solid rgba(255, 255, 255, 0.1);
      opacity: 0.8;
    }

    .loader-cube .front  { transform: rotateY(0deg) translateZ(60px); }
    .loader-cube .back   { transform: rotateY(180deg) translateZ(60px); }
    .loader-cube .right  { transform: rotateY(90deg) translateZ(60px); }
    .loader-cube .left   { transform: rotateY(-90deg) translateZ(60px); }
    .loader-cube .top    { transform: rotateX(90deg) translateZ(60px); }
    .loader-cube .bottom { transform: rotateX(-90deg) translateZ(60px); }

    @keyframes rotateCube {
      0% { transform: rotateX(0deg) rotateY(0deg); }
      25% { transform: rotateX(90deg) rotateY(90deg); }
      50% { transform: rotateX(180deg) rotateY(180deg); }
      75% { transform: rotateX(270deg) rotateY(270deg); }
      100% { transform: rotateX(360deg) rotateY(360deg); }
    }

    .loading-text {
      color: var(--white);
      font-size: 24px;
      font-weight: 600;
      letter-spacing: 2px;
      animation: pulse 1.5s ease-in-out infinite;
    }

    @keyframes pulse {
      0%, 100% { opacity: 0.5; transform: scale(1); }
      50% { opacity: 1; transform: scale(1.05); }
    }

    .loading-dots {
      display: flex;
      gap: 8px;
    }

    .loading-dots span {
      width: 12px;
      height: 12px;
      background: var(--accent-brown);
      border-radius: 50%;
      animation: bounce 1.4s ease-in-out infinite;
    }

    .loading-dots span:nth-child(1) { animation-delay: 0s; }
    .loading-dots span:nth-child(2) { animation-delay: 0.2s; }
    .loading-dots span:nth-child(3) { animation-delay: 0.4s; }

    @keyframes bounce {
      0%, 80%, 100% { transform: translateY(0) scale(1); }
      40% { transform: translateY(-20px) scale(1.2); }
    }

    .progress-bar {
      width: 300px;
      height: 6px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 3px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--accent-brown) 0%, var(--light-beige) 100%);
      border-radius: 3px;
      animation: progress 2s ease-in-out infinite;
    }

    @keyframes progress {
      0% { width: 0%; }
      50% { width: 70%; }
      100% { width: 100%; }
    }

    .logo-text {
      color: var(--white);
      font-size: 32px;
      font-weight: 700;
      margin-bottom: 20px;
    }

    .logo-text span {
      color: var(--accent-brown);
    }
  </style>
<link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="loading-page">
  <div class="logo-text">△ CAPSTONE <span>FUZZY</span></div>
  
  <div class="loader-container">
    <div class="loader-cube">
      <div class="front"></div>
      <div class="back"></div>
      <div class="right"></div>
      <div class="left"></div>
      <div class="top"></div>
      <div class="bottom"></div>
    </div>
  </div>

  <div class="loading-text">LOADING</div>
  
  <div class="loading-dots">
    <span></span>
    <span></span>
    <span></span>
  </div>

  <div class="progress-bar">
    <div class="progress-fill"></div>
  </div>

  <script>
    // Redirect to login after 3 seconds
    setTimeout(() => {
      window.location.href = 'index.php';
    }, 3000);
  </script>
</body>
</html>
