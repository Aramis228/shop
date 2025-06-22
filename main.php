<?php
$conn = new mysqli('localhost', 'root', '', 'magazine');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Обработка AJAX POST
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];

    if ($action === "add" && isset($_POST["name"], $_POST["amount"])) {
        $name = $conn->real_escape_string($_POST["name"]);
        $amount = (float)$_POST["amount"];
        if ($name && $amount > 0) {
            $conn->query("INSERT INTO dolgi (name, amount) VALUES ('$name', $amount)");
            $last_id = $conn->insert_id;
            // Запись в историю
            $conn->query("INSERT INTO dolgi_history (debt_id, change_amount, change_type, comment) VALUES ($last_id, $amount, 'add', 'Добавлен долг')");
            echo json_encode(["status" => "ok", "message" => "Долг добавлен"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Неверные данные"]);
        }
        exit();
    }

    if ($action === "edit" && isset($_POST["id"], $_POST["name"], $_POST["amount"])) {
        $id = (int)$_POST["id"];
        $name = $conn->real_escape_string($_POST["name"]);
        $amount = (float)$_POST["amount"];
        if ($id > 0 && $name && $amount > 0) {
            $conn->query("UPDATE dolgi SET name='$name', amount=$amount WHERE id=$id");
            // Запись в историю
            $conn->query("INSERT INTO dolgi_history (debt_id, change_amount, change_type, comment) VALUES ($id, $amount, 'edit', 'Обновлен долг')");
            echo json_encode(["status" => "ok", "message" => "Долг обновлен"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Неверные данные"]);
        }
        exit();
    }

    if ($action === "delete" && isset($_POST["id"])) {
        $id = (int)$_POST["id"];
        if ($id > 0) {
            // Запишем в историю удаление с нулевой суммой
            $conn->query("INSERT INTO dolgi_history (debt_id, change_amount, change_type, comment) VALUES ($id, 0, 'delete', 'Удален долг')");
            $conn->query("DELETE FROM dolgi WHERE id=$id");
            echo json_encode(["status" => "ok", "message" => "Долг удален"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Неверный ID"]);
        }
        exit();
    }

    if ($action === "export") {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="dolgi_export.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Имя', 'Сумма']);
        $res = $conn->query("SELECT * FROM dolgi ORDER BY id");
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [$row['id'], $row['name'], $row['amount']]);
        }
        fclose($output);
        exit();
    }

    if ($action === "import" && isset($_FILES['csvfile'])) {
        $file = $_FILES['csvfile']['tmp_name'];
        if (($handle = fopen($file, "r")) !== false) {
            fgetcsv($handle); // пропустить заголовок
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (count($data) >= 3) {
                    $name = $conn->real_escape_string($data[1]);
                    $amount = (float)$data[2];
                    if ($name && $amount > 0) {
                        $conn->query("INSERT INTO dolgi (name, amount) VALUES ('$name', $amount)");
                        $last_id = $conn->insert_id;
                        $conn->query("INSERT INTO dolgi_history (debt_id, change_amount, change_type, comment) VALUES ($last_id, $amount, 'add', 'Импорт из CSV')");
                    }
                }
            }
            fclose($handle);
            echo json_encode(["status" => "ok", "message" => "Импорт завершен"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Не удалось открыть файл"]);
        }
        exit();
    }
}

// Обработка AJAX GET для получения истории
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['action']) && $_GET['action'] === 'get_history' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $historyRes = $conn->query("SELECT change_amount, change_type, change_date, comment FROM dolgi_history WHERE debt_id=$id ORDER BY change_date DESC");
    $history = [];
    while ($row = $historyRes->fetch_assoc()) {
        $history[] = $row;
    }
    echo json_encode(["status" => "ok", "history" => $history]);
    exit();
}

// Получаем данные для отображения
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $conn->real_escape_string($_GET['sort']) : 'id';
$order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'DESC' : 'ASC';

$allowedSort = ['id', 'name', 'amount'];
if (!in_array($sort, $allowedSort)) $sort = 'id';

$where = $search ? "WHERE name LIKE '%$search%'" : "";

$sql = "SELECT * FROM dolgi $where ORDER BY $sort $order";
$results = $conn->query($sql);
if (!$results) {
    die("Ошибка запроса: " . $conn->error);
}

$sumResult = $conn->query("SELECT SUM(amount) as total FROM dolgi $where");
$sumRow = $sumResult->fetch_assoc();
$totalDebt = $sumRow['total'] ? number_format($sumRow['total'], 2) : "0.00";

?>

<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Учет долгов клиентов с историей</title>
  <link rel="stylesheet" href="style.css" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body>

  <div class="container">
    <h1>Учет долгов клиентов с историей</h1>

    <input type="text" id="searchInput" placeholder="Поиск по имени..." autocomplete="off">

    <button class="btn-add" id="openModalBtn">Добавить долг</button>
    <button id="exportBtn">Экспорт CSV</button>
    <button id="importBtn">Импорт CSV</button>
    <input type="file" id="importFileInput" accept=".csv">

    <table>
      <thead>
        <tr>
          <th data-sort="name">Имя</th>
          <th data-sort="amount" style="width: 130px;">Сумма (₽)</th>
          <th style="width: 240px;">Действия</th>
        </tr>
      </thead>
      <tbody id="debtsTableBody">
        <?php while ($row = $results->fetch_assoc()): ?>
          <tr data-id="<?= $row['id'] ?>">
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= number_format($row['amount'], 2) ?></td>
            <td>
            <div class="actions-container">
              <button class="btn-history" title="История изменений">История</button>
              <button class="btn-edit" title="Редактировать">Редактировать</button>
              <button class="btn-del" title="Удалить">Удалить</button>
            </td>
            </div>
          </tr>
        <?php endwhile; ?>
      </tbody>
      <tfoot>
        <tr>
          <td><strong>Итого:</strong></td>
          <td colspan="2"><strong><?= $totalDebt ?> ₽</strong></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Модальное окно добавления/редактирования -->
  <div id="modal" aria-hidden="true" role="dialog" aria-labelledby="modalTitle">
    <div id="modal-content">
      <h3 id="modalTitle">Добавить долг</h3>
      <input type="text" id="inputName" placeholder="Имя покупателя" autocomplete="off" />
      <input type="number" id="inputAmount" placeholder="Сумма долга" min="0.01" step="0.01" />
      <button class="btn-save">Сохранить</button>
      <button class="btn-cancel">Отмена</button>
    </div>
  </div>

  <!-- Модальное окно истории -->
  <div id="historyModal" aria-hidden="true" role="dialog" aria-labelledby="historyModalTitle">
    <div id="historyModal-content">
      <h3 id="historyModalTitle">История изменений</h3>
      <ul id="historyList"></ul>
      <button class="btn-close">Закрыть</button>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast"></div>

<!-- Модальное окно подтверждения удаления -->
<div id="confirmDeleteModal" aria-hidden="true" role="dialog" aria-labelledby="confirmDeleteTitle">
  <div id="confirmDeleteModal-content">
    <h3 id="confirmDeleteTitle">Подтвердите удаление</h3>
    <p>Вы уверены, что хотите удалить этот долг?</p>
    <div style="display: flex; justify-content: space-between; margin-top: 24px;">
      <button id="confirmDeleteBtn" class="btn-del" style="flex: 1; margin-right: 10px;">Удалить</button>
      <button id="cancelDeleteBtn" class="btn-cancel" style="flex: 1;">Отмена</button>
    </div>
  </div>
</div>


<script>
  const modal = document.getElementById('modal');
  const historyModal = document.getElementById('historyModal');
  const btnOpenModal = document.getElementById('openModalBtn');
  const btnSave = modal.querySelector('.btn-save');
  const btnCancel = modal.querySelector('.btn-cancel');
  const inputName = document.getElementById('inputName');
  const inputAmount = document.getElementById('inputAmount');
  const toast = document.getElementById('toast');
  const debtsTableBody = document.getElementById('debtsTableBody');
  const searchInput = document.getElementById('searchInput');
  const btnExport = document.getElementById('exportBtn');
  const btnImport = document.getElementById('importBtn');
  const importFileInput = document.getElementById('importFileInput');
  const historyList = document.getElementById('historyList');
  const historyModalCloseBtn = historyModal.querySelector('.btn-close');

  let editMode = false;
  let editId = null;

  // --- Toast функция ---
  function showToast(msg, isError = false) {
    toast.textContent = msg;
    toast.style.backgroundColor = isError ? '#ff3b30' : '#007aff';
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2500);
  }

  // --- Открыть модальное окно добавления ---
  btnOpenModal.onclick = () => {
    editMode = false;
    editId = null;
    inputName.value = '';
    inputAmount.value = '';
    modal.querySelector('h3').textContent = 'Добавить долг';
    openModal(modal);
  };

  // --- Закрыть модальное ---
  btnCancel.onclick = () => closeModal(modal);
  historyModalCloseBtn.onclick = () => closeModal(historyModal);

  function openModal(modalEl) {
    modalEl.classList.add('active');
    modalEl.setAttribute('aria-hidden', 'false');
  }
  function closeModal(modalEl) {
    modalEl.classList.remove('active');
    modalEl.setAttribute('aria-hidden', 'true');
  }

  // --- Сохранение (добавление/редактирование) ---
  btnSave.onclick = () => {
    const name = inputName.value.trim();
    const amount = parseFloat(inputAmount.value.trim());

    if (!name) {
      showToast('Введите имя', true);
      return;
    }
    if (!amount || amount <= 0) {
      showToast('Введите корректную сумму', true);
      return;
    }

    let formData = new FormData();
    formData.append('name', name);
    formData.append('amount', amount.toFixed(2));

    if (editMode) {
      formData.append('id', editId);
      formData.append('action', 'edit');
    } else {
      formData.append('action', 'add');
    }

    fetch(window.location.pathname, {
      method: 'POST',
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'ok') {
        showToast(data.message);
        closeModal(modal);
        reloadPage();
      } else {
        showToast(data.message || 'Ошибка', true);
      }
    })
    .catch(() => showToast('Ошибка сервера', true));
  };

  // --- Редактировать ---
  debtsTableBody.addEventListener('click', e => {
    if (e.target.classList.contains('btn-edit')) {
      const tr = e.target.closest('tr');
      editId = tr.dataset.id;
      editMode = true;
      inputName.value = tr.children[0].textContent;
      inputAmount.value = tr.children[1].textContent.replace(/\s/g, '').replace(',', '.');
      modal.querySelector('h3').textContent = 'Редактировать долг';
      openModal(modal);
    }
  });


  // --- Показать историю ---
  debtsTableBody.addEventListener('click', e => {
    if (e.target.classList.contains('btn-history')) {
      const tr = e.target.closest('tr');
      const id = tr.dataset.id;

      fetch(window.location.pathname + '?action=get_history&id=' + id)
        .then(res => res.json())
        .then(data => {
          if (data.status === 'ok') {
            renderHistory(data.history);
            openModal(historyModal);
          } else {
            showToast('Ошибка загрузки истории', true);
          }
        })
        .catch(() => showToast('Ошибка сервера', true));
    }
  });

  function renderHistory(history) {
    historyList.innerHTML = '';
    if (history.length === 0) {
      historyList.innerHTML = '<li>История изменений отсутствует</li>';
      return;
    }
    history.forEach(item => {
      const li = document.createElement('li');
      const date = new Date(item.change_date).toLocaleString('ru-RU');
      let sign = '';
      if(item.change_type === 'add') sign = '+';
      else if(item.change_type === 'delete') sign = '-';
      else if(item.change_type === 'edit') sign = '';
      li.innerHTML = `<strong>${date}</strong> — ${item.change_type === 'add' ? 'Добавлено' : (item.change_type === 'edit' ? 'Обновлено' : 'Удалено')} 
                      ${sign}${parseFloat(item.change_amount).toFixed(2)} ₽ ${item.comment ? '(' + item.comment + ')' : ''}`;
      historyList.appendChild(li);
    });
  }

  // --- Перезагрузить страницу (чтобы обновить таблицу) ---
  function reloadPage() {
    location.reload();
  }

  // --- Поиск ---
  searchInput.addEventListener('input', () => {
    const val = searchInput.value.trim();
    const url = new URL(window.location);
    if(val) {
      url.searchParams.set('search', val);
    } else {
      url.searchParams.delete('search');
    }
    window.history.replaceState(null, '', url);
    // Можно сделать AJAX обновление таблицы, но пока просто reload:
    reloadPage();
  });

  // --- Сортировка столбцов ---
  document.querySelectorAll('thead th[data-sort]').forEach(th => {
    th.style.cursor = 'pointer';
    th.onclick = () => {
      const sortKey = th.dataset.sort;
      const url = new URL(window.location);
      let currentSort = url.searchParams.get('sort') || 'id';
      let currentOrder = url.searchParams.get('order') || 'asc';

      if(currentSort === sortKey) {
        currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
      } else {
        currentOrder = 'asc';
      }

      url.searchParams.set('sort', sortKey);
      url.searchParams.set('order', currentOrder);
      window.history.replaceState(null, '', url);
      reloadPage();
    };
  });

  // --- Экспорт CSV ---
  btnExport.onclick = () => {
    // Просто перезагружаем страницу с параметром action=export для скачивания
    const url = new URL(window.location);
    url.searchParams.set('action', 'export');
    window.location = url;
  };

  // --- Импорт CSV ---
  btnImport.onclick = () => {
    importFileInput.click();
  };
  importFileInput.onchange = () => {
    const file = importFileInput.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('action', 'import');
    formData.append('csvfile', file);

    fetch(window.location.pathname, {
      method: 'POST',
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'ok') {
        showToast(data.message);
        reloadPage();
      } else {
        showToast(data.message || 'Ошибка импорта', true);
      }
    })
    .catch(() => showToast('Ошибка сервера', true));
  };

const confirmDeleteModal = document.getElementById('confirmDeleteModal');
const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');

let deleteId = null; // id долга для удаления

// Перехватываем клик на кнопке удаления в таблице
debtsTableBody.addEventListener('click', e => {
  if (e.target.classList.contains('btn-del')) {
    e.preventDefault();
    const tr = e.target.closest('tr');
    deleteId = tr.dataset.id;
    openModal(confirmDeleteModal);
  }
});

// Кнопка подтвердить удаление
confirmDeleteBtn.onclick = () => {
  if (!deleteId) return;

  let formData = new FormData();
  formData.append('action', 'delete');
  formData.append('id', deleteId);

  fetch(window.location.pathname, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.status === 'ok') {
      showToast(data.message);
      closeModal(confirmDeleteModal);
      reloadPage();
    } else {
      showToast(data.message || 'Ошибка', true);
    }
  })
  .catch(() => showToast('Ошибка сервера', true));
};

// Кнопка отмена удаления
cancelDeleteBtn.onclick = () => {
  deleteId = null;
  closeModal(confirmDeleteModal);
};


</script>
</body>
</html>
