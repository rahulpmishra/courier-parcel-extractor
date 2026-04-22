(function () {
    var panel = document.querySelector('[data-autorefresh]');
    if (!panel) {
        return;
    }

    var refreshSeconds = parseInt(panel.getAttribute('data-autorefresh'), 10);
    if (!refreshSeconds || refreshSeconds <= 0) {
        return;
    }

    var statusUrl = panel.getAttribute('data-job-status-url');
    var pageUrl = panel.getAttribute('data-job-page-url') || window.location.href;

    if (!statusUrl) {
        window.setTimeout(function () {
            window.location.reload();
        }, refreshSeconds * 1000);
        return;
    }

    var statusKicker = panel.querySelector('[data-status-kicker]');
    var statusPill = panel.querySelector('[data-status-pill]');
    var statusMessage = panel.querySelector('[data-status-message]');
    var statusSubnote = panel.querySelector('[data-status-subnote]');
    var progressBar = panel.querySelector('[data-progress-bar]');
    var progressCopy = panel.querySelector('[data-progress-copy]');
    var fileCount = panel.querySelector('[data-file-count]');
    var pollWarning = panel.querySelector('[data-poll-warning]');
    var refreshHint = panel.querySelector('[data-refresh-hint]');
    var active = true;

    function updateWarning(messages) {
        if (!pollWarning) {
            return;
        }

        if (!messages || !messages.length) {
            pollWarning.remove();
            pollWarning = null;
            return;
        }

        var items = messages.map(function (message) {
            return '<li>' + message + '</li>';
        }).join('');
        pollWarning.innerHTML = '<ul>' + items + '</ul>';
    }

    function applyJobState(payload) {
        var job = payload.job || {};
        var status = (job.status || 'unknown').toLowerCase();
        var progress = parseInt(job.progress, 10);

        if (Number.isNaN(progress)) {
            progress = 0;
        }

        if (statusKicker) {
            statusKicker.textContent = payload.status_kicker || '';
        }

        if (statusPill) {
            statusPill.textContent = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown';
            statusPill.className = 'status-pill status-' + status;
        }

        if (statusMessage) {
            statusMessage.textContent = job.message || 'Waiting for update...';
        }

        if (statusSubnote) {
            statusSubnote.textContent = payload.status_subnote || '';
        }

        if (progressBar) {
            progressBar.style.width = Math.max(0, Math.min(progress, 100)) + '%';
        }

        if (progressCopy) {
            progressCopy.textContent = progress + '% synced';
        }

        if (fileCount) {
            fileCount.textContent = job.file_count || 0;
        }

        updateWarning(payload.poll_warnings || []);

        if (payload.is_finished) {
            active = false;
            window.location.assign(pageUrl);
            return;
        }

        if (refreshHint) {
            refreshHint.textContent = status === 'canceling'
                ? 'Live telemetry is syncing in the background while this run releases.'
                : 'Live telemetry is syncing in the background while this run stays active.';
        }
    }

    function poll() {
        if (!active) {
            return;
        }

        window.fetch(statusUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Polling failed with status ' + response.status + '.');
                }
                return response.json();
            })
            .then(function (payload) {
                applyJobState(payload);
            })
            .catch(function (error) {
                updateWarning(['Temporary polling issue: ' + error.message]);
            })
            .finally(function () {
                if (active) {
                    window.setTimeout(poll, refreshSeconds * 1000);
                }
            });
    }

    window.setTimeout(poll, refreshSeconds * 1000);
})();

(function () {
    var senderInput = document.getElementById('sender-input');
    var imagesInput = document.getElementById('images-input');
    var folderInput = document.getElementById('folder-input');

    if (!senderInput || !imagesInput || !folderInput) {
        return;
    }

    function setSenderValue(value) {
        if (!value) {
            return;
        }

        senderInput.value = value;
    }

    function folderNameFromSelection(fileList) {
        if (!fileList || !fileList.length) {
            return '';
        }

        var firstFile = fileList[0];
        var relativePath = firstFile.webkitRelativePath || '';
        if (!relativePath) {
            return '';
        }

        var parts = relativePath.split('/').filter(Boolean);
        return parts.length ? parts[0] : '';
    }

    imagesInput.addEventListener('change', function () {
        if (folderInput.files && folderInput.files.length) {
            return;
        }

        if (imagesInput.files && imagesInput.files.length) {
            setSenderValue('others');
        }
    });

    folderInput.addEventListener('change', function () {
        if (folderInput.files && folderInput.files.length) {
            var folderName = folderNameFromSelection(folderInput.files);
            setSenderValue(folderName || 'others');
            return;
        }

        if (imagesInput.files && imagesInput.files.length) {
            setSenderValue('others');
            return;
        }

        senderInput.value = '';
    });
})();

(function () {
    var uploadForm = document.querySelector('.upload-form');
    if (!uploadForm) {
        return;
    }

    var submitButton = uploadForm.querySelector('button[type="submit"]');
    if (!submitButton) {
        return;
    }

    var defaultLabel = submitButton.getAttribute('data-default-label') || submitButton.textContent;
    var busyLabel = submitButton.getAttribute('data-busy-label') || 'Submitting...';

    uploadForm.addEventListener('submit', function (event) {
        if (uploadForm.classList.contains('is-submitting')) {
            event.preventDefault();
            return;
        }

        uploadForm.classList.add('is-submitting');
        uploadForm.setAttribute('aria-busy', 'true');
        submitButton.disabled = true;
        submitButton.textContent = busyLabel;

        window.setTimeout(function () {
            if (!document.body.contains(uploadForm)) {
                return;
            }

            if (uploadForm.classList.contains('is-submitting')) {
                submitButton.textContent = defaultLabel;
                submitButton.disabled = false;
                uploadForm.classList.remove('is-submitting');
                uploadForm.removeAttribute('aria-busy');
            }
        }, 15000);
    });
})();

(function () {
    var table = document.querySelector('[data-date-sort-table]');
    if (!table) {
        return;
    }

    var button = table.querySelector('[data-date-sort-button]');
    var tbody = table.querySelector('tbody');

    if (!button || !tbody) {
        return;
    }

    function parseDateValue(text) {
        var value = (text || '').trim();
        if (!value) {
            return 0;
        }

        var timestamp = Date.parse(value + 'T00:00:00');
        return Number.isNaN(timestamp) ? 0 : timestamp;
    }

    function sortRows(direction) {
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function (leftRow, rightRow) {
            var leftDate = parseDateValue(leftRow.cells[0] ? leftRow.cells[0].textContent : '');
            var rightDate = parseDateValue(rightRow.cells[0] ? rightRow.cells[0].textContent : '');

            if (leftDate === rightDate) {
                var leftFile = leftRow.cells[1] ? leftRow.cells[1].textContent : '';
                var rightFile = rightRow.cells[1] ? rightRow.cells[1].textContent : '';
                return leftFile.localeCompare(rightFile, undefined, { numeric: true, sensitivity: 'base' });
            }

            return direction === 'asc' ? leftDate - rightDate : rightDate - leftDate;
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });

        button.dataset.sortDirection = direction;
        button.textContent = direction === 'asc' ? 'Date ↑' : 'Date ↓';
    }

    button.addEventListener('click', function () {
        sortRows('desc');
    });

    button.addEventListener('dblclick', function (event) {
        event.preventDefault();
        sortRows('asc');
    });

    sortRows(button.dataset.sortDirection || 'desc');
})();

(function () {
    var table = document.querySelector('[data-master-table]');
    if (!table) {
        return;
    }

    var dateButton = table.querySelector('[data-date-sort-button]');
    var emptyButtons = Array.prototype.slice.call(table.querySelectorAll('[data-empty-sort-button]'));
    var senderSearch = document.querySelector('[data-master-sender-search]');
    var tbody = table.querySelector('tbody');

    if (!dateButton || !tbody) {
        return;
    }

    var activeMode = {
        type: 'date',
        direction: dateButton.dataset.sortDirection || 'desc',
        columnIndex: null
    };

    Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function (row, index) {
        row.dataset.originalIndex = String(index);
    });

    function parseDateValue(text) {
        var value = (text || '').trim();
        if (!value) {
            return 0;
        }

        var timestamp = Date.parse(value + 'T00:00:00');
        return Number.isNaN(timestamp) ? 0 : timestamp;
    }

    function rowText(row, columnIndex) {
        return row.cells[columnIndex] ? row.cells[columnIndex].textContent.trim() : '';
    }

    function originalIndex(row) {
        return parseInt(row.dataset.originalIndex || '0', 10);
    }

    function compareOriginal(leftRow, rightRow) {
        return originalIndex(leftRow) - originalIndex(rightRow);
    }

    function compareByDate(direction) {
        return function (leftRow, rightRow) {
            var leftDate = parseDateValue(rowText(leftRow, 0));
            var rightDate = parseDateValue(rowText(rightRow, 0));

            if (leftDate === rightDate) {
                return rowText(leftRow, 1).localeCompare(rowText(rightRow, 1), undefined, {
                    numeric: true,
                    sensitivity: 'base'
                });
            }

            return direction === 'asc' ? leftDate - rightDate : rightDate - leftDate;
        };
    }

    function compareByEmptyColumn(columnIndex) {
        return function (leftRow, rightRow) {
            var leftEmpty = rowText(leftRow, columnIndex) === '';
            var rightEmpty = rowText(rightRow, columnIndex) === '';

            if (leftEmpty !== rightEmpty) {
                return leftEmpty ? -1 : 1;
            }

            return compareOriginal(leftRow, rightRow);
        };
    }

    function currentComparator() {
        if (activeMode.type === 'empty') {
            return compareByEmptyColumn(activeMode.columnIndex);
        }

        if (activeMode.type === 'original') {
            return compareOriginal;
        }

        return compareByDate(activeMode.direction);
    }

    function senderQuery() {
        return senderSearch ? senderSearch.value.trim().toLowerCase() : '';
    }

    function applyRows() {
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var query = senderQuery();
        var comparator = currentComparator();

        rows.sort(function (leftRow, rightRow) {
            if (query) {
                var leftMatch = rowText(leftRow, 2).toLowerCase().indexOf(query) !== -1;
                var rightMatch = rowText(rightRow, 2).toLowerCase().indexOf(query) !== -1;

                if (leftMatch !== rightMatch) {
                    return leftMatch ? -1 : 1;
                }
            }

            return comparator(leftRow, rightRow);
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });
    }

    function resetEmptyButtonLabels() {
        emptyButtons.forEach(function (button) {
            button.textContent = button.dataset.baseLabel || button.textContent;
        });
    }

    function sortRows(direction) {
        activeMode = {
            type: 'date',
            direction: direction,
            columnIndex: null
        };

        resetEmptyButtonLabels();
        dateButton.dataset.sortDirection = direction;
        dateButton.textContent = direction === 'asc' ? 'Date oldest' : 'Date newest';
        applyRows();
    }

    function sortEmptyRows(button) {
        var columnIndex = parseInt(button.dataset.columnIndex || '-1', 10);
        if (columnIndex < 0) {
            return;
        }

        activeMode = {
            type: 'empty',
            direction: 'empty',
            columnIndex: columnIndex
        };

        resetEmptyButtonLabels();
        dateButton.textContent = 'Date';
        button.textContent = (button.dataset.baseLabel || button.textContent) + ' empty';
        applyRows();
    }

    function resetRows() {
        activeMode = {
            type: 'original',
            direction: 'original',
            columnIndex: null
        };

        resetEmptyButtonLabels();
        dateButton.textContent = 'Date';
        applyRows();
    }

    emptyButtons.forEach(function (button) {
        button.dataset.baseLabel = button.textContent.trim();

        button.addEventListener('click', function () {
            sortEmptyRows(button);
        });

        button.addEventListener('dblclick', function (event) {
            event.preventDefault();
            resetRows();
        });
    });

    if (senderSearch) {
        senderSearch.addEventListener('input', applyRows);
    }

    dateButton.addEventListener('click', function () {
        sortRows('desc');
    });

    dateButton.addEventListener('dblclick', function (event) {
        event.preventDefault();
        sortRows('asc');
    });

    sortRows(dateButton.dataset.sortDirection || 'desc');
})();
