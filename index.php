<?php
session_start();

// KONEKSI DATABASE
$koneksi = mysqli_connect("127.0.0.1", "root", "", "dompetku");

if (!$koneksi) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

$pesan = "";

// REGISTER
if (isset($_POST['register'])) {
    $nama = trim($_POST['nama']);
    $hp = trim($_POST['hp']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = mysqli_prepare(
        $koneksi,
        "INSERT INTO users (nama, hp, password) VALUES (?, ?, ?)"
    );

    mysqli_stmt_bind_param($stmt, "sss", $nama, $hp, $password);

    if (mysqli_stmt_execute($stmt)) {
        $pesan = "Pendaftaran berhasil. Silakan login.";
    } else {
        $pesan = "Nomor HP sudah digunakan atau pendaftaran gagal.";
    }

    mysqli_stmt_close($stmt);
}

// LOGIN
if (isset($_POST['login'])) {
    $hp = trim($_POST['hp']);
    $password = $_POST['password'];

    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id, password FROM users WHERE hp = ?"
    );

    mysqli_stmt_bind_param($stmt, "s", $hp);
    mysqli_stmt_execute($stmt);

    $hasil = mysqli_stmt_get_result($stmt);
    $user_login = mysqli_fetch_assoc($hasil);

    if ($user_login && password_verify($password, $user_login['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user_login['id'];

        header("Location: index.php");
        exit;
    } else {
        $pesan = "Nomor HP atau password salah.";
    }

    mysqli_stmt_close($stmt);
}

// LOGOUT
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();

    header("Location: index.php");
    exit;
}

// TRANSAKSI
if (isset($_POST['transaksi']) && isset($_SESSION['user_id'])) {

    $jumlah = filter_var($_POST['jumlah'], FILTER_VALIDATE_INT);
    $jenis = $_POST['jenis'];
    $keterangan = trim($_POST['keterangan']);
    $user_id = $_SESSION['user_id'];

    if (
        !$jumlah ||
        $jumlah < 1 ||
        !in_array($jenis, ['masuk', 'keluar'], true)
    ) {
        $pesan = "Jumlah transaksi tidak valid.";
    } else {

        mysqli_begin_transaction($koneksi);

        try {
            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT saldo FROM users WHERE id = ? FOR UPDATE"
            );

            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $akun = mysqli_fetch_assoc($hasil);
            mysqli_stmt_close($stmt);

            if (!$akun) {
                throw new Exception("Akun tidak ditemukan.");
            }

            if ($jenis == "keluar" && $akun['saldo'] < $jumlah) {
                throw new Exception("Saldo tidak mencukupi.");
            }

            if ($jenis == "masuk") {
                $sql = "UPDATE users SET saldo = saldo + ? WHERE id = ?";
            } else {
                $sql = "UPDATE users SET saldo = saldo - ? WHERE id = ?";
            }

            $stmt = mysqli_prepare($koneksi, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $jumlah, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO transaksi
                (user_id, jenis, jumlah, keterangan)
                VALUES (?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "isis",
                $user_id,
                $jenis,
                $jumlah,
                $keterangan
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($koneksi);

            header("Location: index.php");
            exit;

        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            $pesan = $e->getMessage();
        }
    }
}

// AMBIL DATA USER
$user = null;
$riwayat = [];

if (isset($_SESSION['user_id'])) {

    $user_id = $_SESSION['user_id'];

    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT * FROM users WHERE id = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);

    $hasil = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($hasil);
    mysqli_stmt_close($stmt);

    if (!$user) {
        session_unset();
        session_destroy();
        header("Location: index.php");
        exit;
    }

    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT * FROM transaksi
         WHERE user_id = ?
         ORDER BY tanggal DESC"
    );

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);

    $hasil = mysqli_stmt_get_result($stmt);

    while ($data = mysqli_fetch_assoc($hasil)) {
        $riwayat[] = $data;
    }

    mysqli_stmt_close($stmt);
}

// PENGAMAN TAMPILAN
function aman($teks) {
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>DompetKu</title>

    <link rel="stylesheet" href="style.css">
</head>

<body>

<div class="container">

<?php if (!$user): ?>

    <h1 class="logo">DompetKu</h1>
    <p class="welcome">Selamat datang di DompetKu!</p>

    <?php if ($pesan): ?>
        <div class="pesan"><?= aman($pesan) ?></div>
    <?php endif; ?>

    <hr>

    <h3>Login</h3>

    <form method="POST">
        <input type="text" name="hp" placeholder="Nomor HP" required>

        <input type="password" name="password" placeholder="Password" required>

        <button type="submit" name="login">Login</button>
    </form>

    <hr>

    <h3>Register</h3>

    <form method="POST">
        <input type="text" name="nama" placeholder="Nama lengkap" required>

        <input type="text" name="hp" placeholder="Nomor HP" required>

        <input type="password" name="password" placeholder="Buat password" required>

        <button type="submit" name="register">Daftar</button>
    </form>

<?php else: ?>

    <h1 class="logo">DompetKu</h1>

    <p class="sapaan">
        Halo, <?= aman($user['nama']) ?>!
    </p>

    <div class="saldo">
        <p>Saldo DompetKu</p>

        <h2>
            Rp <?= number_format($user['saldo'], 0, ',', '.') ?>
        </h2>
    </div>

    <div class="menu-utama">

        <div class="menu-item"
             onclick="document.getElementById('tambah').scrollIntoView({behavior:'smooth'})">
            <div class="ikon">+</div>
            <p>Tambah Uang</p>
        </div>

        <div class="menu-item"
             onclick="document.getElementById('keluar').scrollIntoView({behavior:'smooth'})">
            <div class="ikon">−</div>
            <p>Pengeluaran</p>
        </div>

        <div class="menu-item"
             onclick="document.getElementById('riwayat').scrollIntoView({behavior:'smooth'})">
            <div class="ikon">≡</div>
            <p>Riwayat</p>
        </div>

    </div>

    <?php if ($pesan): ?>
        <div class="pesan"><?= aman($pesan) ?></div>
    <?php endif; ?>

    <hr>

    <h3 id="tambah">Tambah Uang</h3>

    <form method="POST">
        <input type="hidden" name="jenis" value="masuk">

        <input
            type="number"
            name="jumlah"
            placeholder="Masukkan jumlah uang"
            min="1"
            required
        >

        <input
            type="text"
            name="keterangan"
            placeholder="Keterangan"
        >

        <button type="submit" name="transaksi">
            Tambah Saldo
        </button>
    </form>

    <hr>

    <h3 id="keluar">Pengeluaran</h3>

    <form method="POST">
        <input type="hidden" name="jenis" value="keluar">

        <input
            type="number"
            name="jumlah"
            placeholder="Jumlah pengeluaran"
            min="1"
            required
        >

        <input
            type="text"
            name="keterangan"
            placeholder="Keterangan pengeluaran"
        >

        <button type="submit" name="transaksi">
            Simpan Pengeluaran
        </button>
    </form>

    <hr>

    <h3 id="riwayat">Riwayat Transaksi</h3>

    <?php if (count($riwayat) > 0): ?>

        <?php foreach ($riwayat as $data): ?>

            <div class="riwayat">

                <strong>
                    <?= $data['jenis'] == 'masuk'
                        ? 'Uang Masuk'
                        : 'Pengeluaran' ?>
                </strong>

                <p>
                    Rp <?= number_format($data['jumlah'], 0, ',', '.') ?>
                </p>

                <p><?= aman($data['keterangan']) ?></p>

                <p><?= aman($data['tanggal']) ?></p>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <p class="kosong">Belum ada transaksi.</p>

    <?php endif; ?>

    <hr>

    <a href="index.php?logout=1">
        <button type="button" class="logout">Logout</button>
    </a>

<?php endif; ?>

</div>

</body>
</html>
