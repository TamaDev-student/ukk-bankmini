import { jsPDF } from "jspdf";
import autoTable from "jspdf-autotable";

const generatedAt = () =>
    new Intl.DateTimeFormat("id-ID", {
        dateStyle: "long",
        timeStyle: "short",
        timeZone: "Asia/Jakarta",
    }).format(new Date());

export function downloadReportPdf({
    title,
    subtitle,
    filename,
    columns,
    rows,
    summary = [],
    notes = [],
    generatedAt: reportGeneratedAt,
}) {
    if (!Array.isArray(rows)) {
        throw new Error("Data laporan tidak valid untuk diekspor.");
    }

    const doc = new jsPDF({
        orientation: columns.length > 6 ? "landscape" : "portrait",
        unit: "pt",
        format: "a4",
        compress: true,
    });
    const pageWidth = doc.internal.pageSize.getWidth();
    const margin = 36;

    doc.setFont("helvetica", "bold");
    doc.setFontSize(10);
    doc.setTextColor(71, 85, 105);
    doc.text("E-TELLER BANK MINI SEKOLAH", margin, 38);

    doc.setFontSize(17);
    doc.setTextColor(15, 23, 42);
    doc.text(title, margin, 64);

    doc.setFont("helvetica", "normal");
    doc.setFontSize(9);
    doc.setTextColor(71, 85, 105);
    doc.text(subtitle, margin, 82, {
        maxWidth: pageWidth - margin * 2,
    });
    doc.text(
        `Dibuat: ${reportGeneratedAt ?? generatedAt()}`,
        margin,
        98
    );

    let tableStart = 116;
    if (summary.length > 0) {
        autoTable(doc, {
            startY: tableStart,
            body: summary,
            theme: "plain",
            margin: { left: margin, right: margin },
            styles: {
                font: "helvetica",
                fontSize: 8,
                cellPadding: 3,
                textColor: [51, 65, 85],
            },
            columnStyles: {
                0: { cellWidth: 150, fontStyle: "bold" },
            },
            didParseCell: (data) => {
                if (
                    data.section === "body" &&
                    typeof data.row.raw?.[0] === "string" &&
                    data.row.raw[0].startsWith("BAGIAN ")
                ) {
                    data.cell.styles.fillColor = [239, 246, 255];
                    data.cell.styles.textColor = [30, 64, 175];
                    data.cell.styles.fontStyle = "bold";
                }
            },
        });
        tableStart = doc.lastAutoTable.finalY + 12;
    }

    const reportNotes =
        rows.length === 0 ? ["Tidak ada transaksi pada periode ini."] : [];
    if (notes.length > 0 || reportNotes.length > 0) {
        const noteText = [...notes, ...reportNotes].filter(Boolean).join(" ");
        const noteLines = doc.splitTextToSize(noteText, pageWidth - margin * 2);
        doc.setFontSize(8);
        doc.setTextColor(71, 85, 105);
        doc.text(noteLines, margin, tableStart);
        tableStart += noteLines.length * 10 + 8;
    }

    autoTable(doc, {
        startY: tableStart,
        head: [columns],
        body: rows,
        theme: "grid",
        margin: { left: margin, right: margin, bottom: 34 },
        styles: {
            font: "helvetica",
            fontSize: columns.length > 7 ? 6.5 : 7.5,
            cellPadding: 4,
            overflow: "linebreak",
            valign: "middle",
        },
        headStyles: {
            fillColor: [30, 64, 175],
            textColor: [255, 255, 255],
            fontStyle: "bold",
        },
        alternateRowStyles: {
            fillColor: [248, 250, 252],
        },
        didDrawPage: () => {
            const pageHeight = doc.internal.pageSize.getHeight();
            const pageCount = doc.getNumberOfPages();
            doc.setFontSize(8);
            doc.setTextColor(100, 116, 139);
            doc.text(
                `Halaman ${doc.internal.getCurrentPageInfo().pageNumber} dari ${pageCount}`,
                pageWidth - margin,
                pageHeight - 16,
                { align: "right" }
            );
        },
    });

    doc.save(filename);
}
