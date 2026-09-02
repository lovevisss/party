<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class AuthorizationTemplateService
{
    public function make(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'authorization-template-');
        if ($path === false) {
            throw new RuntimeException('无法创建模板临时文件。');
        }

        $strings = [
            '工号/统一账号', '姓名', '学院代码', '系统角色', '岗位标签', '启用状态',
            '填写说明', '学院提交人：学院代码必须与人员同步数据一致，岗位标签选择“组织员”或“办公室主任”。',
            '校级业务管理员、系统管理员：学院代码和岗位标签留空。',
            '启用状态只能填写“启用”或“停用”；停用表示撤销对应授权。',
            '系统角色可选：学院提交人、校级业务管理员、系统管理员。',
        ];

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法生成 XLSX 模板。');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStrings($strings));
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->authorizationSheet());
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->instructionsSheet());
        $zip->close();

        $contents = file_get_contents($path);
        @unlink($path);
        if ($contents === false) {
            throw new RuntimeException('无法读取生成的 XLSX 模板。');
        }

        return $contents;
    }

    /** @param list<string> $strings */
    private function sharedStrings(array $strings): string
    {
        $items = implode('', array_map(fn (string $value): string => '<si><t>'.$this->escape($value).'</t></si>', $strings));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">'.$items.'</sst>';
    }

    private function authorizationSheet(): string
    {
        $cells = '';
        foreach (range(0, 5) as $index) {
            $column = chr(65 + $index);
            $cells .= '<c r="'.$column.'1" s="1" t="s"><v>'.$index.'</v></c>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:F1000"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="20" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/><col min="3" max="3" width="18" customWidth="1"/><col min="4" max="5" width="22" customWidth="1"/><col min="6" max="6" width="14" customWidth="1"/></cols><sheetData><row r="1" ht="24" customHeight="1">'.$cells.'</row></sheetData><autoFilter ref="A1:F1000"/><dataValidations count="3"><dataValidation type="list" allowBlank="0" showErrorMessage="1" errorTitle="角色填写错误" error="请选择列表中的系统角色" sqref="D2:D1000"><formula1>&quot;学院提交人,校级业务管理员,系统管理员&quot;</formula1></dataValidation><dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="岗位填写错误" error="请选择组织员或办公室主任" sqref="E2:E1000"><formula1>&quot;组织员,办公室主任&quot;</formula1></dataValidation><dataValidation type="list" allowBlank="0" showErrorMessage="1" errorTitle="状态填写错误" error="请选择启用或停用" sqref="F2:F1000"><formula1>&quot;启用,停用&quot;</formula1></dataValidation></dataValidations></worksheet>';
    }

    private function instructionsSheet(): string
    {
        $rows = '<row r="1"><c r="A1" s="1" t="s"><v>6</v></c></row>';
        foreach (range(7, 10) as $index) {
            $row = $index - 5;
            $rows .= '<row r="'.$row.'"><c r="A'.$row.'" t="s"><v>'.$index.'</v></c></row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="110" customWidth="1"/></cols><sheetData>'.$rows.'</sheetData></worksheet>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="授权名单" sheetId="1" r:id="rId1"/><sheet name="填写说明" sheetId="2" r:id="rId2"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Microsoft YaHei"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Microsoft YaHei"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF173B32"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs></styleSheet>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
