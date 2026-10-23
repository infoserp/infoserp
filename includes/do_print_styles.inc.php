        /* â”€â”€ Screen shell â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        body {
            font-family: 'Inter', sans-serif;
            background: #e8eaf0;
        }

        /* â”€â”€ A4 paper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .a4-paper {
            width: 210mm;
            background: #ffffff;
            margin: 0 auto;
            position: relative;
            box-shadow: 0 4px 32px rgba(0,0,0,0.18);
        }

        /* Screen preview: show page break guides */
        @media screen {
            .a4-paper {
                min-height: 297mm;
            }
        }

        /* â”€â”€ Print zones â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .print-header-zone {
            /* letterhead + title + customer info â€” keep on page 1 */
        }

        .print-table-zone {
            margin-bottom: 2mm;
        }

        /* Closing block: signature + disclaimer + footer image stay together */
        .print-closing-block {
            margin-top: 6mm;
        }

        /* â”€â”€ Inner content area â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .doc-content {
            padding: 0 14mm 14mm 14mm;
        }

        /* â”€â”€ Letterhead image area â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .letterhead-area {
            height: 38mm;
            border-bottom: 1.5px solid #1e40af;
            margin: 0 0 6mm 0;
            overflow: hidden;
        }

        /* â”€â”€ Document title â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .doc-title {
            font-size: 15pt;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            text-decoration: underline;
            text-underline-offset: 4px;
            text-align: center;
            color: #111827;
            margin: 0 0 5mm 0;
        }

        /* â”€â”€ Info boxes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4mm;
            margin-bottom: 5mm;
        }

        .info-box {
            border: 1.2px solid #374151;
            border-radius: 5px;
            padding: 3mm 4mm;
            font-size: 8pt;
        }

        .info-box-label {
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.06em;
            margin-bottom: 1.5mm;
        }

        .info-box-company {
            font-size: 9pt;
            font-weight: 700;
            color: #111827;
            margin-bottom: 1.5mm;
        }

        .info-box-address {
            font-size: 8pt;
            color: #374151;
            line-height: 1.5;
        }

        .info-row {
            display: flex;
            gap: 2mm;
            font-size: 8pt;
            line-height: 2;
            color: #374151;
        }

        .info-row-key {
            font-weight: 600;
            min-width: 14mm;
            color: #4b5563;
        }

        .info-row-sep { color: #9ca3af; }

        .info-row-val {
            font-weight: 700;
            color: #111827;
        }

        /* â”€â”€ Items table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-bottom: 5mm;
        }

        .items-table thead tr {
            background: #1e40af;
        }

        .items-table thead th {
            color: #ffffff;
            font-weight: 700;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 2.5mm 3mm;
            border: 1px solid #1e40af;
            text-align: left;
        }

        .items-table thead th.center { text-align: center; }

        .items-table tbody td {
            padding: 2.5mm 3mm;
            border: 1px solid #d1d5db;
            color: #1f2937;
            vertical-align: top;
            line-height: 1.45;
        }

        .items-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        /* Prevent a single row from splitting across pages */
        .items-table tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .items-table tfoot td {
            padding: 2mm 3mm;
            font-size: 7.5pt;
            color: #6b7280;
        }

        /* â”€â”€ Remarks â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .remarks-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 2.5mm 3.5mm;
            font-size: 8pt;
            color: #374151;
            margin-bottom: 5mm;
            background: #f9fafb;
        }

        .remarks-label {
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.06em;
            margin-bottom: 1mm;
        }

        /* â”€â”€ Document footer (branch + receiver signature) â”€â”€ */
        .doc-footer-section {
            display: grid;
            grid-template-columns: 1fr 1.35fr;
            gap: 8mm;
            margin-top: 8mm;
            margin-bottom: 4mm;
            align-items: start;
        }

        .branch-block {
            border-top: 1.5px solid #374151;
            padding-top: 2.5mm;
        }

        .branch-block-label {
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #4b5563;
            letter-spacing: 0.06em;
            margin-bottom: 3mm;
        }

        .branch-block-name {
            font-size: 10pt;
            font-weight: 700;
            color: #111827;
            line-height: 1.35;
            margin-bottom: 1.5mm;
        }

        .branch-block-sub {
            font-size: 7pt;
            color: #6b7280;
        }

        /* â”€â”€ Received By / digital signature pad â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .received-block {
            border-top: 1.5px solid #374151;
            padding-top: 2.5mm;
        }

        .received-block-label {
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #4b5563;
            letter-spacing: 0.06em;
            margin-bottom: 2mm;
        }

        .dig-sig-pad {
            position: relative;
            height: 22mm;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2.5mm;
        }

        .dig-sig-watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .dig-sig-watermark span {
            font-size: 9pt;
            font-weight: 800;
            color: rgba(148,163,184,0.4);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transform: rotate(-18deg);
            text-align: center;
            line-height: 1.35;
            white-space: pre-line;
        }

        .dig-sig-img {
            position: relative;
            z-index: 2;
            max-height: 20mm;
            max-width: 100%;
            object-fit: contain;
        }

        .receiver-name-row {
            display: flex;
            align-items: baseline;
            gap: 2mm;
            border-top: 1px solid #9ca3af;
            padding-top: 1.5mm;
            margin-bottom: 1.5mm;
        }

        .receiver-name-key {
            font-size: 7pt;
            font-weight: 600;
            color: #6b7280;
            white-space: nowrap;
        }

        .receiver-name-val {
            flex: 1;
            font-size: 8pt;
            font-weight: 700;
            color: #111827;
            min-height: 4mm;
        }

        .dig-sig-meta {
            font-size: 7pt;
            color: #6b7280;
            display: flex;
            flex-wrap: wrap;
            gap: 2mm;
            margin-bottom: 1mm;
        }

        .dig-sig-meta-key {
            font-weight: 600;
            color: #374151;
        }

        .dig-sig-timestamp {
            font-size: 6.5pt;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: 1.5mm;
        }

        /* â”€â”€ Generated-by bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .generated-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px dashed #e5e7eb;
            padding-top: 2.5mm;
            margin-bottom: 4mm;
        }

        .generated-terms {
            font-size: 7pt;
            color: #9ca3af;
        }

        .generated-by {
            font-size: 7pt;
            font-weight: 700;
            color: #9ca3af;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        /* â”€â”€ Footer image area â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .footer-area {
            height: 22mm;
            border-top: 1.5px solid #1e40af;
            overflow: hidden;
            margin-top: auto;
        }

        /* â”€â”€ Screen-only toolbar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(30,64,175,0.3);
        }

        /* â”€â”€ Print media â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 10mm 10mm 10mm;
            }

            body {
                background: white;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .print-toolbar  { display: none !important; }
            .screen-spacer  { display: none !important; }
            .screen-wrap    { padding: 0 !important; }

            .a4-paper {
                box-shadow: none;
                margin: 0;
                width: 100%;
                min-height: auto;
            }

            .doc-content {
                padding: 0;
            }

            /* Repeat table header on every printed page */
            .items-table thead {
                display: table-header-group;
            }

            .items-table tfoot {
                display: table-footer-group;
            }

            /* Keep document header block together on first page */
            .print-header-zone {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .letterhead-area {
                page-break-after: avoid;
                break-after: avoid;
            }

            .info-grid {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            /* Table rows never split mid-row */
            .items-table tbody tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            /* Continuation label row */
            .page-continue-row td {
                background: #f1f5f9 !important;
                font-size: 7pt;
                font-weight: 600;
                color: #64748b;
                text-align: center;
                padding: 1.5mm 3mm;
            }

            /* Signature + disclaimer + footer image â€” never split internally */
            .print-closing-block {
                page-break-inside: avoid;
                break-inside: avoid;
                page-break-before: auto;
            }

            .doc-footer-section {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .generated-bar {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .footer-area {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
