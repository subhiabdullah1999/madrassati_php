<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

class TeacherDataExport implements FromCollection, WithTitle, WithHeadings, ShouldAutoSize, WithStrictNullComparison, WithMultipleSheets {
    protected mixed $results;
    protected Collection $formFields;

    
    public function title(): string {
        return 'Teacher Bulk Registration';
    }

    public function headings(): array {
        $columns = [
            'first_name',
            'last_name',
            'mobile',
            'email',
            'gender',
            'dob',
            'qualification',
            'current address',
            'permanent address',
            'salary'
        ];
        return $columns;
    }

    public function sheets(): array {
        $sheets = [];

        // add the main data sheet
        $sheets[] = new TeacherDataExport();

        return $sheets;
    }

    private function getActionItems() {
        $fields = [
            'test',
            'example',
            '1234567899',
            'guaridan@example.com',
            'male/female',
            date('d-m-Y'),
            'B ed',
            'Norway',
            'Norway',
            '10000'
        ];
      
        return $fields;
    }

    public function collection() {
        // store the results for later use
        $this->results = $this->getActionItems();

        return collect(array($this->results));
    }
}
